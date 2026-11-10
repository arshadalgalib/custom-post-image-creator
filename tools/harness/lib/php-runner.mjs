/**
 * Shared php-wasm session helper for the verification harness.
 *
 * The wasm filesystem cannot see the repository, so PHP sources and the
 * bundled fonts are inlined as heredoc payloads and written into the VM before
 * the target script runs. The repo is never mounted, so nothing in the plugin
 * needs to know this harness exists.
 */

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, relative } from 'node:path';
import { PhpNode } from 'php-wasm/PhpNode';
import mbstring from 'php-wasm-mbstring';
import sqlite from 'php-wasm-sqlite';
import openssl from 'php-wasm-openssl';
import zlib from 'php-wasm-zlib';

export const VM_ROOT = '/plugin';

// Binary assets the plugin expects to find on disk.
const BINARY_EXTENSIONS = ['.ttf', '.otf', '.woff', '.woff2', '.png', '.jpg', '.jpeg', '.webp'];
const ASSET_ROOTS = ['fonts'];

/** Collect files under a directory whose name matches a predicate. */
function walk(dir, predicate, out = []) {
	if (!existsSync(dir)) {
		return out;
	}

	for (const entry of readdirSync(dir)) {
		if (entry === 'node_modules' || entry === '.git') {
			continue;
		}
		const full = join(dir, entry);
		if (statSync(full).isDirectory()) {
			walk(full, predicate, out);
		} else if (predicate(entry)) {
			out.push(full);
		}
	}

	return out;
}

/** Every file the suite needs inside the VM filesystem. */
export function pluginFiles(repoRoot) {
	const files = [
		...walk(join(repoRoot, 'src'), (name) => name.endsWith('.php')),
		...walk(join(repoRoot, 'tests'), (name) => name.endsWith('.php')),
		...walk(join(repoRoot, 'templates'), (name) => name.endsWith('.php')),
		...walk(join(repoRoot, 'tools', 'harness'), (name) => name.endsWith('.php')),
		...walk(join(repoRoot, 'releases'), (name) => name.endsWith('.php')),
		join(repoRoot, 'custom-post-image-creator.php'),
		join(repoRoot, 'uninstall.php'),
	];

	for (const root of ASSET_ROOTS) {
		files.push(...walk(join(repoRoot, root), (name) => BINARY_EXTENSIONS.some((ext) => name.endsWith(ext))));
	}

	return files.filter(existsSync);
}

/**
 * Largest payload written by a single PHP script.
 *
 * php-wasm compiles each script with a fixed interpreter stack, and one script
 * holding every heredoc overflows it once the binary assets grow (a single
 * extra bundled font was enough). Batching keeps each compilation small.
 */
export const WRITE_BATCH_BYTES = 512 * 1024;

/**
 * Build the PHP scripts that write every plugin file into the VM filesystem.
 *
 * @param {string}   repoRoot Repository root.
 * @param {string[]} files    Absolute file paths.
 * @param {number}   [budget] Bytes of payload per script.
 * @returns {string[]} PHP sources, in write order.
 */
export function buildFileWriters(repoRoot, files, budget = WRITE_BATCH_BYTES) {
	const payloads = files.map((file, index) => {
		const bytes = readFileSync(file);
		const binary = BINARY_EXTENSIONS.some((ext) => file.endsWith(ext));

		return {
			vmPath: join(VM_ROOT, relative(repoRoot, file)),
			body: binary ? bytes.toString('base64') : bytes.toString('utf8'),
			binary,
			end: `CPIC_PAYLOAD_${index}_${bytes.length.toString(16)}`,
		};
	});

	for (const payload of payloads) {
		if (new RegExp(`^${payload.end}$`, 'm').test(payload.body)) {
			throw new Error(`Heredoc terminator collision in ${payload.vmPath}`);
		}
	}

	const batches = [];
	let batch = [];
	let size = 0;

	for (const payload of payloads) {
		const length = payload.body.length + 256;

		if (batch.length && size + length > budget) {
			batches.push(batch);
			batch = [];
			size = 0;
		}

		batch.push(payload);
		size += length;
	}

	if (batch.length) {
		batches.push(batch);
	}

	return batches.map((rows, index) => `<?php
// Batch ${index + 1} of ${batches.length}.
$cpic_files = array();
${rows
	.map(
		(p) => `$cpic_files[${JSON.stringify(p.vmPath)}] = <<<'${p.end}'
${p.body}
${p.end};`
	)
	.join('\n')}

$cpic_binary = ${JSON.stringify(rows.filter((p) => p.binary).map((p) => p.vmPath))};

foreach ( $cpic_files as $path => $contents ) {
	$dir = dirname( $path );
	if ( ! is_dir( $dir ) ) {
		mkdir( $dir, 0777, true );
	}

	if ( in_array( $path, $cpic_binary, true ) ) {
		$contents = base64_decode( $contents );
	}

	file_put_contents( $path, $contents );
}
`);
}

/**
 * Build a single script that writes every plugin file (small payloads only).
 *
 * @param {string}   repoRoot Repository root.
 * @param {string[]} files    Absolute file paths.
 * @returns {string} PHP source.
 */
export function buildFileWriter(repoRoot, files) {
	const scripts = buildFileWriters(repoRoot, files, Number.MAX_SAFE_INTEGER);
	return scripts[0];
}

/**
 * Create a PHP session with the extensions the plugin relies on.
 *
 * @returns {Promise<{run: (code: string) => Promise<[number,string]>, dispose: () => Promise<void>}>}
 */
export async function createSession() {
	const php = new PhpNode({
		version: '8.3',
		sharedLibs: [mbstring, sqlite, openssl, zlib],
		dynamicLibs: [sqlite, openssl, zlib],
	});

	let buffer = '';
	php.addEventListener('output', (event) => {
		// The payload is a wasm-backed string; copy it now.
		buffer += String(event.detail[0]);
	});

	return {
		async run(code) {
			buffer = '';
			let exitCode = 0;

			try {
				exitCode = await php.run(code);
			} catch (error) {
				return [255, `${buffer}\n[fatal] ${error?.message || error}\n`];
			}

			return [exitCode, buffer];
		},
		async dispose() {},
	};
}

/**
 * Prepare a session: write the plugin into the VM, then run PHP with the
 * plugin root and test bootstrap environment already set up.
 *
 * @param {string} repoRoot Repository root.
 */
export async function createPluginSession(repoRoot) {
	const session = await createSession();
	const scripts = buildFileWriters(repoRoot, pluginFiles(repoRoot));

	for (const [index, script] of scripts.entries()) {
		const [code, output] = await session.run(script);

		if (code !== 0) {
			throw new Error(`php-wasm could not write the plugin files (batch ${index + 1} of ${scripts.length}):\n${output.slice(-2000)}`);
		}
	}

	return {
		run(phpCode) {
			const prelude = `<?php
putenv( 'CPIC_ROOT=${VM_ROOT}' );
putenv( 'CPIC_TEST_TMP=/tmp/cpic-tests' );
$_SERVER['SERVER_NAME'] = 'example.test';
$_SERVER['HTTP_HOST']   = 'example.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
`;
			return session.run(`${prelude}${phpCode}`);
		},
		dispose: session.dispose,
	};
}
