/**
 * Syntax gate: asks php-wasm to lint every PHP file in the plugin.
 * Fast (one process) and needs no PHP binary.
 *
 * Usage: node tools/harness/php-lint.mjs [repoRoot]
 */

import { readFileSync, readdirSync, statSync, existsSync } from 'node:fs';
import { join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createSession } from './lib/php-runner.mjs';

const here = fileURLToPath(new URL('.', import.meta.url));
const repoRoot = (process.argv[2] || join(here, '..', '..')).replace(/\/+$/, '');

function walk(dir, out = []) {
	if (!existsSync(dir)) {
		return out;
	}
	for (const entry of readdirSync(dir)) {
		if (entry === 'node_modules' || entry === '.git') {
			continue;
		}
		const full = join(dir, entry);
		if (statSync(full).isDirectory()) {
			walk(full, out);
		} else if (entry.endsWith('.php')) {
			out.push(full);
		}
	}
	return out;
}

const files = [
	...walk(join(repoRoot, 'src')),
	...walk(join(repoRoot, 'tests')),
	...walk(join(repoRoot, 'templates')),
	...walk(join(repoRoot, 'includes')),
	join(repoRoot, 'custom-post-image-creator.php'),
	join(repoRoot, 'uninstall.php'),
].filter(existsSync);

const payloads = files.map((file, index) => ({
	name: relative(repoRoot, file),
	body: readFileSync(file, 'utf8'),
	end: `CPIC_LINT_${index}`,
}));

const script = `<?php
$cpic_lint = array();
${payloads
	.map(
		(p) => `$cpic_lint[${JSON.stringify(p.name)}] = <<<'${p.end}'
${p.body}
${p.end};`
	)
	.join('\n')}

$failures = 0;
foreach ( $cpic_lint as $name => $code ) {
	// TOKEN_PARSE compiles the source and throws ParseError on bad syntax.
	try {
		token_get_all( $code, TOKEN_PARSE );
	} catch ( \ParseError $e ) {
		$failures++;
		echo "FAIL " . $name . " :: line " . $e->getLine() . ": " . $e->getMessage() . "\n";
	} catch ( \Throwable $e ) {
		$failures++;
		echo "FAIL " . $name . " :: " . $e->getMessage() . "\n";
	}
}

echo "checked=" . count( $cpic_lint ) . " failures=" . $failures . "\\n";
exit( $failures > 0 ? 1 : 0 );
`;

const session = await createSession();
const [code, output] = await session.run(script);
process.stdout.write(output);
process.exit(code === 0 ? 0 : 1);
