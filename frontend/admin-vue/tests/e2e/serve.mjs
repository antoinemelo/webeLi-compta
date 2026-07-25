import { rmSync, mkdirSync } from 'node:fs';
import { resolve } from 'node:path';
import { spawn, spawnSync } from 'node:child_process';

const frontend = resolve(import.meta.dirname, '../..');
const root = resolve(frontend, '../..');
const storage = resolve(frontend, 'test-results/storage');
rmSync(storage, { recursive: true, force: true });
mkdirSync(storage, { recursive: true });

const environment = {
  ...process.env,
  APP_ENV: 'e2e',
  APP_DEBUG: '1',
  APP_INSTANCE_ID: 'compta-e2e',
  APP_BASE_URL: '/e2e',
  APP_STORAGE_PATH: storage,
  APP_DB_PATH: resolve(storage, 'app.sqlite'),
  APP_VUE_SHELL_ENABLED: '1'
};

const seed = spawnSync('php', [resolve(frontend, 'tests/e2e/seed.php')], {
  cwd: root,
  env: environment,
  stdio: 'inherit'
});
if (seed.status !== 0) process.exit(seed.status ?? 1);

const server = spawn(
  'php',
  ['-S', '127.0.0.1:8093', '-t', resolve(root, 'public'), resolve(root, 'tools/dev-router.php')],
  { cwd: root, env: environment, stdio: 'inherit' }
);

const stop = () => server.kill('SIGTERM');
process.on('SIGTERM', stop);
process.on('SIGINT', stop);
server.on('exit', (code) => process.exit(code ?? 0));
