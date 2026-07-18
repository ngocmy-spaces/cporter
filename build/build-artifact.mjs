#!/usr/bin/env node
/**
 * cPorter — build deploy artifact (.zip)
 *
 * Steps (see docs/SPEC.md §14):
 *   1. Build the web SPA (apps/web) → dist
 *   2. Copy dist into apps/api/public (Laravel serves the SPA)
 *   3. Package apps/api (must already have vendor/ from `composer install --no-dev`)
 *      into build/out/cporter-<version>.zip
 *   4. Print SHA-256 (CI sends this to the Deploy API for integrity verification)
 *
 * NOTE: This script does NOT run `composer install` — CI must run it before packaging
 * (the local dev box may not have PHP/Composer). The zip uses ext-zip-friendly entries
 * so cPorter's ZipArchive::extractTo() can unpack it on cPanel.
 */
import { createHash } from 'node:crypto';
import { appendFileSync, createReadStream, createWriteStream, existsSync, mkdirSync, rmSync, cpSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { execFileSync } from 'node:child_process';
import archiver from 'archiver';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const API = join(ROOT, 'apps/api');
const WEB = join(ROOT, 'apps/web');
const OUT = join(ROOT, 'build/out');

const pkg = JSON.parse(readFileSync(join(ROOT, 'package.json'), 'utf8'));
const version = process.env.CPORTER_VERSION ?? pkg.version ?? '0.0.0';

function step(msg) { console.log(`\n▸ ${msg}`); }

step('Build web SPA (apps/web)');
execFileSync('pnpm', ['--filter', '@cporter/web', 'build'], { cwd: ROOT, stdio: 'inherit' });

step('Copy web dist → apps/api/public');
const dist = join(WEB, 'dist');
if (!existsSync(dist)) throw new Error(`Web build not found at ${dist}`);
cpSync(dist, join(API, 'public'), { recursive: true });

if (!existsSync(join(API, 'vendor'))) {
  console.warn('⚠  apps/api/vendor not found — run `composer install --no-dev --optimize-autoloader` in apps/api before packaging.');
}

step('Package artifact (.zip)');
mkdirSync(OUT, { recursive: true });
const zipPath = join(OUT, `cporter-${version}.zip`);
if (existsSync(zipPath)) rmSync(zipPath);

await new Promise((resolvePromise, reject) => {
  const output = createWriteStream(zipPath);
  const archive = archiver('zip', { zlib: { level: 9 } });
  output.on('close', resolvePromise);
  archive.on('error', reject);
  archive.pipe(output);
  // Ship the whole Laravel app; exclude dev-only + local env.
  archive.glob('**/*', {
    cwd: API,
    dot: true,
    ignore: [
      '.env', '.git/**', 'tests/**',
      'storage/logs/*', 'storage/framework/cache/*', 'storage/framework/sessions/*', 'storage/framework/views/*',
      // Runtime data (uploaded/staged artifacts, private disk) — never ship in the deploy artifact.
      // `storage` is a shared symlink on the host anyway (shared_paths), so these dirs come from there.
      'storage/app/artifacts/**', 'storage/app/private/**', 'storage/app/public/**',
    ],
  });
  archive.finalize();
});

step('Compute SHA-256');
const sha = await new Promise((resolvePromise, reject) => {
  const hash = createHash('sha256');
  const rs = createReadStream(zipPath);
  rs.on('error', reject);
  rs.on('data', (c) => hash.update(c));
  rs.on('end', () => resolvePromise(hash.digest('hex')));
});

console.log(`\n✔ Artifact: ${zipPath}`);
console.log(`  version:  ${version}`);
console.log(`  sha256:   ${sha}`);

// Expose results to GitHub Actions (used by the deploy step).
if (process.env.GITHUB_OUTPUT) {
  appendFileSync(process.env.GITHUB_OUTPUT, `artifact=${zipPath}\nsha256=${sha}\nversion=${version}\n`);
}
