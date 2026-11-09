/**
 * Run a standalone PHP script (e.g. activation-smoke.php) inside the harness VM.
 */
import { createPluginSession, VM_ROOT } from './lib/php-runner.mjs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const repo = resolve(here, '../..');
const script = process.argv[2] || 'tools/harness/activation-smoke.php';

const session = await createPluginSession(repo);
const [code, output] = await session.run(`require '${VM_ROOT}/${script}';`);
process.stdout.write(output);
process.exit(code === 0 ? 0 : 1);
