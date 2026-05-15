#!/usr/bin/env node
import { spawn, spawnSync } from 'node:child_process';
import { existsSync, unlinkSync, writeFileSync } from 'node:fs';
import { resolve } from 'node:path';
import waitOn from 'wait-on';

const ROOT = resolve(import.meta.dirname, '..', '..');
const BACKEND = resolve(ROOT, 'backend');
const SQLITE_PATH = resolve(BACKEND, 'database', 'e2e.sqlite');
const BACKEND_HOST = '127.0.0.1';
const BACKEND_PORT = 8001;
const BACKEND_URL = `http://${BACKEND_HOST}:${BACKEND_PORT}`;
const READY_URL = `${BACKEND_URL}/api/v1/auth/registration-status`;

// All env vars passed via spawn env override any .env file (Dotenv is immutable).
const BACKEND_ENV = {
  APP_NAME: 'WFH E2E',
  APP_ENV: 'testing',
  APP_KEY: 'base64:VJRXrzxJJRCytx0zsicofq7fIM1Yfi48A1jN3JF2re0=',
  APP_DEBUG: 'true',
  APP_URL: BACKEND_URL,
  APP_TIMEZONE: 'Asia/Jakarta',
  APP_LOCALE: 'en',
  APP_FALLBACK_LOCALE: 'en',
  APP_FAKER_LOCALE: 'en_US',
  APP_MAINTENANCE_DRIVER: 'file',
  BCRYPT_ROUNDS: '4',
  LOG_CHANNEL: 'stack',
  LOG_STACK: 'single',
  LOG_LEVEL: 'debug',
  DB_CONNECTION: 'sqlite',
  DB_DATABASE: SQLITE_PATH,
  SESSION_DRIVER: 'array',
  SESSION_LIFETIME: '120',
  BROADCAST_CONNECTION: 'log',
  FILESYSTEM_DISK: 'local',
  QUEUE_CONNECTION: 'sync',
  CACHE_STORE: 'array',
  MAIL_MAILER: 'array',
  SANCTUM_STATEFUL_DOMAINS: 'localhost:5174,127.0.0.1:5174',
  FRONTEND_URL: 'http://localhost:5174',
  RECAPTCHA_SECRET_KEY: 'e2e-test-secret-bypassed',
  ENABLE_REGISTRATION: 'true',
  CORS_ALLOWED_ORIGINS: 'http://localhost:5174',
  WHATSAPP_API_URL: 'http://127.0.0.1:65535/api-mock',
};

let backendProc = null;
let cleanedUp = false;

function log(msg) {
  process.stdout.write(`[e2e] ${msg}\n`);
}

function cleanup(code = 0) {
  if (cleanedUp) return;
  cleanedUp = true;
  if (backendProc && !backendProc.killed) {
    log('Stopping backend...');
    try {
      backendProc.kill('SIGTERM');
    } catch {}
  }
  try {
    if (existsSync(SQLITE_PATH)) unlinkSync(SQLITE_PATH);
  } catch {}
  process.exit(code);
}

process.on('SIGINT', () => cleanup(130));
process.on('SIGTERM', () => cleanup(143));
process.on('uncaughtException', (err) => {
  console.error('[e2e] uncaught:', err);
  cleanup(1);
});

function killPort(port) {
  const r = spawnSync('sh', ['-c', `lsof -ti:${port} | xargs kill -9 2>/dev/null; true`]);
  return r.status === 0;
}

async function main() {
  log(`Killing any process on ports 8001 and 5174`);
  killPort(BACKEND_PORT);
  killPort(5174);

  log(`Resetting sqlite at ${SQLITE_PATH}`);
  if (existsSync(SQLITE_PATH)) unlinkSync(SQLITE_PATH);
  writeFileSync(SQLITE_PATH, '');

  const artisanEnv = { ...process.env, ...BACKEND_ENV };

  log('Running migrate:fresh');
  const migrate = spawnSync(
    'php',
    ['artisan', 'migrate:fresh', '--force'],
    { cwd: BACKEND, stdio: 'inherit', env: artisanEnv }
  );
  if (migrate.status !== 0) {
    log('Migration failed.');
    cleanup(migrate.status || 1);
    return;
  }

  log('Seeding E2ESeeder');
  const seed = spawnSync(
    'php',
    ['artisan', 'db:seed', '--class=E2ESeeder', '--force'],
    { cwd: BACKEND, stdio: 'inherit', env: artisanEnv }
  );
  if (seed.status !== 0) {
    log('Seeding failed.');
    cleanup(seed.status || 1);
    return;
  }

  log(`Starting backend on ${BACKEND_URL}`);
  backendProc = spawn(
    'php',
    [
      'artisan',
      'serve',
      `--host=${BACKEND_HOST}`,
      `--port=${BACKEND_PORT}`,
    ],
    {
      cwd: BACKEND,
      stdio: ['ignore', 'pipe', 'pipe'],
      env: artisanEnv,
    }
  );

  let backendDied = false;
  backendProc.on('exit', (code) => {
    if (!cleanedUp) {
      backendDied = true;
      log(`Backend exited unexpectedly (code=${code})`);
    }
  });
  backendProc.stderr.on('data', (chunk) => {
    const text = chunk.toString();
    // Filter noisy request logs; only surface errors
    if (text.includes('Error') || text.includes('Fatal') || text.includes('Warning') || text.includes('Notice') || text.includes('Failed')) {
      process.stderr.write('[backend] ' + text);
    }
  });
  backendProc.stdout.on('data', (chunk) => {
    const text = chunk.toString();
    if (text.includes('Error') || text.includes('Fatal') || text.includes('Failed')) {
      process.stderr.write('[backend] ' + text);
    }
  });

  log('Waiting for backend ready...');
  try {
    await waitOn({ resources: [READY_URL], timeout: 30_000, interval: 250, validateStatus: (s) => s >= 200 && s < 500 });
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
  const pw = spawn('npx', ['playwright', 'test', ...extraArgs], {
    cwd: resolve(import.meta.dirname, '..'),
    stdio: 'inherit',
    env: { ...process.env, E2E_API_BASE: `${BACKEND_URL}/api/v1` },
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
