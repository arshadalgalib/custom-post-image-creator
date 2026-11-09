/**
 * Run a standalone PHP script inside the harness VM.
 *
 * Usage: node smoke.mjs tools/harness/<script>.php
 *        CPIC_PHP_VERSION=8.1 node smoke.mjs ...   # test another PHP version
 */
import { createPluginSession, VM_ROOT } from './lib/php-runner.mjs';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const repo = process.env.CPIC_REPO_ROOT || resolve(here, '../..');
const script = process.argv[2] || 'tools/harness/activation-smoke.php';

const session = await createPluginSession(repo);
const [code, output] = await session.run(`require '${VM_ROOT}/${script}';`);
process.stdout.write(output);
process.exit(code === 0 ? 0 : 1);
