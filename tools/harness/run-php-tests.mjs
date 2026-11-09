/**
 * Runs the plugin's PHP suite inside php-wasm (WebAssembly PHP) against a real
 * SQLite database, so the code actually executes on a machine with no PHP
 * binary. Development only.
 *
 * Usage: node tools/harness/run-php-tests.mjs [repoRoot] [--quiet]
 * Exit code 0 only when the suite passes with zero failures.
 */

import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createPluginSession, VM_ROOT } from './lib/php-runner.mjs';

const here = fileURLToPath(new URL('.', import.meta.url));
const args = process.argv.slice(2);
const quiet = args.includes('--quiet');
const repoRoot = (args.find((a) => !a.startsWith('--')) || join(here, '..', '..')).replace(/\/+$/, '');

const started = Date.now();
const session = await createPluginSession(repoRoot);

const [exitCode, output] = await session.run(`require '${VM_ROOT}/tests/php/run-core.php';`);

const durationMs = Date.now() - started;

process.stdout.write(output);

const passed = (output.match(/^\s+✓/gm) || []).length;
const failed = (output.match(/^\s+✗/gm) || []).length;

if (!quiet && failed) {
	const failing = output
		.split('\n')
		.filter((line) => /^\s+✗/.test(line))
		.map((line) => line.trim());

	process.stdout.write(`\nFailures:\n${failing.map((line) => `  - ${line}`).join('\n')}\n`);
}

process.stdout.write(`\nphp-wasm exit=${exitCode} elapsed=${(durationMs / 1000).toFixed(1)}s passed=${passed} failed=${failed}\n`);

await session.dispose();

process.exit(exitCode === 0 && failed === 0 ? 0 : 1);
