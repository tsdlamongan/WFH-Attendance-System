#!/usr/bin/env node
/**
 * Boots the whole E2E stack and runs Playwright (`npm test`, `make test-e2e`).
 *
 *   backend/.env.testing   Laravel loads it instead of .env because APP_ENV=testing is exported
 *                          (`artisan serve` passes APP_ENV through to its php -S child, so the
 *                          HTTP server reads the same file). SERVER_PORT and DB_DATABASE are
 *                          read from it here too, so the ports live in one place.
 *   frontend/.env.testing  Loaded by `vite --mode testing` (webServer in playwright.config.js).
 *
 * Steps: free the ports → fresh sqlite file → migrate:fresh + E2ESeeder → artisan serve →
 * wait until it answers → `playwright test` (extra CLI args pass through) → stop the backend
 * and delete the sqlite file.
 */
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, readFileSync, unlinkSync, writeFileSync } from 'node:fs';
import { isAbsolute, resolve } from 'node:path';
import waitOn from 'wait-on';

const IS_WINDOWS = process.platform === 'win32';
const ROOT = resolve(import.meta.dirname, '..', '..');
const BACKEND = resolve(ROOT, 'backend');
const FRONTEND = resolve(ROOT, 'frontend');
const BACKEND_ENV_FILE = resolve(BACKEND, '.env.testing');
const FRONTEND_PORT = 10004; // keep in sync with playwright.config.js (baseURL + webServer)

function log(msg) {
  process.stdout.write(`[e2e] ${msg}\n`);
}

function fail(msg) {
  console.error(`[e2e] ${msg}`);
  process.exit(1);
}

// Minimal .env reader for our own committed file: KEY=VALUE per line, optional quotes,
// `#` comments, no interpolation.
function readEnvFile(path) {
  if (!existsSync(path)) fail(`${path} not found (it is committed; restore it with git checkout).`);
  const env = {};
  for (const rawLine of readFileSync(path, 'utf8').split(/\r?\n/)) {
    const line = rawLine.trim();
    if (!line || line.startsWith('#')) continue;
    const eq = line.indexOf('=');
    if (eq === -1) continue;
    const key = line.slice(0, eq).trim();
    let value = line.slice(eq + 1).trim();
    const quoted =
      (value.startsWith('"') && value.endsWith('"')) || (value.startsWith("'") && value.endsWith("'"));
    if (quoted && value.length >= 2) value = value.slice(1, -1);
    env[key] = value;
  }
  return env;
}

const testingEnv = readEnvFile(BACKEND_ENV_FILE);
if (testingEnv.APP_ENV !== 'testing') fail(`${BACKEND_ENV_FILE} must set APP_ENV=testing`);
if (
  testingEnv.DB_CONNECTION !== 'sqlite' ||
  !testingEnv.DB_DATABASE ||
  testingEnv.DB_DATABASE.includes(':memory:')
) {
  fail(`${BACKEND_ENV_FILE} must use DB_CONNECTION=sqlite with a file DB_DATABASE (it is recreated every run).`);
}

const BACKEND_HOST = testingEnv.SERVER_HOST || '127.0.0.1';
const BACKEND_PORT = Number(testingEnv.SERVER_PORT) || 10003;
const SQLITE_PATH = isAbsolute(testingEnv.DB_DATABASE)
  ? testingEnv.DB_DATABASE
  : resolve(BACKEND, testingEnv.DB_DATABASE);
const BACKEND_URL = `http://${BACKEND_HOST}:${BACKEND_PORT}`;
const READY_URL = `${BACKEND_URL}/api/v1/auth/registration-status`;

// APP_ENV=testing is all Laravel needs to pick .env.testing. Dotenv never overrides variables
// that already exist in the process environment, so nothing else is injected here.
const artisanEnv = { ...process.env, APP_ENV: 'testing' };

let backendProc = null;
let cleanedUp = false;

function cleanup(code = 0) {
  if (cleanedUp) return;
  cleanedUp = true;
  if (backendProc && !backendProc.killed) {
    log('Stopping backend...');
    try {
      // `artisan serve` spawns a child `php -S`; on Windows a signal only reaches the parent, so kill the tree.
      if (IS_WINDOWS) spawnSync('taskkill', ['/pid', String(backendProc.pid), '/T', '/F']);
      else backendProc.kill('SIGTERM');
    } catch {
      // already gone
    }
    // `artisan serve` does not reliably take its `php -S` child down with it (this runner exits
    // right after signalling it), and an orphaned child keeps the port busy for the next run.
    killPort(BACKEND_PORT);
  }
  try {
    if (existsSync(SQLITE_PATH)) unlinkSync(SQLITE_PATH);
  } catch {
    // nothing to remove
  }
  process.exit(code);
}

process.on('SIGINT', () => cleanup(130));
process.on('SIGTERM', () => cleanup(143));
process.on('uncaughtException', (err) => {
  console.error('[e2e] uncaught:', err);
  cleanup(1);
});

function killPort(port) {
  const r = IS_WINDOWS
    ? spawnSync('powershell', [
        '-NoProfile',
        '-Command',
        `Get-NetTCPConnection -State Listen -LocalPort ${port} -ErrorAction SilentlyContinue | ForEach-Object { Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue }`,
      ])
    : spawnSync('sh', ['-c', `lsof -ti:${port} | xargs kill -9 2>/dev/null; true`]);
  return r.status === 0;
}

function artisan(args) {
  return spawnSync('php', ['artisan', ...args], { cwd: BACKEND, stdio: 'inherit', env: artisanEnv });
}

async function main() {
  log(`Using ${BACKEND_ENV_FILE} (backend :${BACKEND_PORT}, frontend :${FRONTEND_PORT})`);

  log(`Killing any process on ports ${BACKEND_PORT} and ${FRONTEND_PORT}`);
  killPort(BACKEND_PORT);
  killPort(FRONTEND_PORT);

  log(`Resetting sqlite at ${SQLITE_PATH}`);
  if (existsSync(SQLITE_PATH)) unlinkSync(SQLITE_PATH);
  writeFileSync(SQLITE_PATH, '');

  log('Running migrate:fresh');
  const migrate = artisan(['migrate:fresh', '--force']);
  if (migrate.status !== 0) {
    log('Migration failed.');
    cleanup(migrate.status || 1);
    return;
  }

  log('Seeding E2ESeeder');
  const seed = artisan(['db:seed', '--class=E2ESeeder', '--force']);
  if (seed.status !== 0) {
    log('Seeding failed.');
    cleanup(seed.status || 1);
    return;
  }

  log(`Starting backend on ${BACKEND_URL}`);
  backendProc = spawn(
    'php',
    ['artisan', 'serve', `--host=${BACKEND_HOST}`, `--port=${BACKEND_PORT}`],
    { cwd: BACKEND, stdio: ['ignore', 'pipe', 'pipe'], env: artisanEnv }
  );

  let backendDied = false;
  backendProc.on('exit', (code) => {
    if (!cleanedUp) {
      backendDied = true;
      log(`Backend exited unexpectedly (code=${code})`);
    }
  });
  const surfaceErrors = (chunk) => {
    const text = chunk.toString();
    // Filter noisy request logs; only surface errors
    if (/Error|Fatal|Warning|Notice|Failed/.test(text)) {
      process.stderr.write('[backend] ' + text);
    }
  };
  backendProc.stderr.on('data', surfaceErrors);
  backendProc.stdout.on('data', surfaceErrors);

  log('Waiting for backend ready...');
  try {
    await waitOn({
      resources: [READY_URL],
      timeout: 30_000,
      interval: 250,
      validateStatus: (s) => s >= 200 && s < 500,
    });
  } catch (err) {
    log(`Backend never became ready: ${err.message}`);
    cleanup(1);
    return;
  }
  if (backendDied) {
    cleanup(1);
    return;
  }
  log('Backend ready.');

  const extraArgs = process.argv.slice(2);
  log(`Running Playwright ${extraArgs.length ? extraArgs.join(' ') : ''}`);
  const pw = spawn(IS_WINDOWS ? 'npx.cmd' : 'npx', ['playwright', 'test', ...extraArgs], {
    cwd: FRONTEND,
    stdio: 'inherit',
    env: { ...process.env, E2E_API_BASE: `${BACKEND_URL}/api/v1` },
    shell: IS_WINDOWS, // Node refuses to spawn .cmd files without a shell on Windows
  });

  pw.on('exit', (code) => {
    log(`Playwright exited (code=${code})`);
    cleanup(code ?? 0);
  });
}

main().catch((err) => {
  console.error('[e2e] fatal:', err);
  cleanup(1);
});
