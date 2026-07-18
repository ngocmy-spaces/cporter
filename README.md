# cPorter

Self-hosted deploy orchestrator that runs on **cPanel shared hosting**. Installed like a regular web app
(monorepo **React + Laravel**, 1 folder / 1 domain), but manages and deploys atomically (release + symlink,
instant rollback) for other domains under the same cPanel account — controlled over an HTTP API from any CI.

## Monorepo structure

```
cporter/
├── apps/
│   ├── api/          # Laravel 12 — Deploy API + Core Engine + Admin API
│   └── web/          # React + Vite + TS — Admin Panel SPA
├── build/            # build-artifact.mjs → bundles FE+BE into a single deployable .zip
├── docs/SPEC.md      # Full technical specification
└── TASKS.md          # Task breakdown by phase
```

On deploy: `apps/web` builds to static → copied into `apps/api/public`; Laravel serves `/api/v1/*` as JSON
and falls back other routes to the SPA. The cPanel docroot points to `deploy.domain/current/public`.

## Dev environment requirements

| Tool | Version | Notes |
|---|---|---|
| Node | ≥ 20 (currently using 24) | for `apps/web` |
| pnpm | ≥ 11 | package manager |
| PHP | 8.2+ (target host: 8.3) | for `apps/api` — **required to run the BE** |
| Composer | 2.x | install Laravel dependencies |
| MySQL | 5.7+/8 | cPorter's DB |

## Getting started (dev)

```bash
# 1) Install all JS deps (root + apps/web) + build script
pnpm install

# 2) Run the Admin SPA (Vite dev server, proxy /api → http://localhost:8000)
pnpm dev:web

# 3) Laravel backend (once you have PHP + Composer)
cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve            # http://localhost:8000
```

## Build deploy artifact

```bash
# CI: run composer install --no-dev in apps/api first, then:
pnpm build:artifact          # → build/out/cporter-<version>.zip + prints sha256
```

## Documentation

- 📄 [Technical specification (SPEC)](docs/SPEC.md)
- ✅ [Task breakdown by phase](TASKS.md)

## Status

Phase 0 — scaffolding the monorepo skeleton. See [TASKS.md](TASKS.md).
