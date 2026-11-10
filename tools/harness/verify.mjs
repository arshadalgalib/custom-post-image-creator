/**
 * Full verification gate.
 *
 * Runs, in order: PHP lint, class/service integrity, activation simulation,
 * the PHP suite, the admin screens, the canvas render and the studio smoke
 * test (which boots the real editor script in a DOM and drives it). Exits
 * non-zero on the first failure so CI and humans get one command and one
 * answer.
 */
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../..');

const steps = [
	['PHP lint', [process.execPath, 'php-lint.mjs', repo]],
	['PHP 7.4 compatibility', [process.execPath, 'smoke.mjs', 'tools/harness/php-compat.php']],
	
	['Class + service integrity', [process.execPath, 'smoke.mjs', 'tools/harness/class-integrity.php']],
	['Activation simulation', [process.execPath, 'smoke.mjs', 'tools/harness/activation-smoke.php']],
	['PHP test suite', [process.execPath, 'run-php-tests.mjs', repo]],
	['Admin screens', [process.execPath, 'render-admin-screens.mjs', repo]],
	['Canvas render', [process.execPath, 'render-canvas.mjs']],
	['Studio smoke (editor JS)', [process.execPath, 'studio-smoke.mjs', repo]],
	['Studio paint (editor canvas)', [process.execPath, 'studio-paint.mjs', repo]],
];

for (const [label, args] of steps) {
	process.stdout.write(`\n▶ ${label}\n`);
	const result = spawnSync(args[0], args.slice(1), { cwd: here, stdio: 'inherit' });

	if (result.status !== 0) {
		process.stdout.write(`\n✗ ${label} failed\n`);
		process.exit(1);
	}
}

process.stdout.write('\n✓ ALL VERIFICATION GATES PASSED\n');
