<p align="center">
  <img src="apps/web/public/favicon.svg" alt="cPorter logo" width="88" height="88" />
</p>

<h1 align="center">cPorter</h1>

<p align="center"><em>Atomic, zero-downtime deploys to cPanel — driven by any CI, no root required.</em></p>

Self-hosted deploy orchestrator that runs on **cPanel shared hosting**. Installed like a regular web app
(monorepo **React + Laravel**, 1 folder / 1 domain), but manages and deploys atomically (release + symlink,
instant rollback) for other domains under the same cPanel account — controlled over an HTTP API from any CI.

## Monorepo structure

```
cporter/
├── apps/
│   ├── api/          # Laravel 12 — Deploy API + Core Engine + Admin API
│   └── web/          # React + Vite + TS — Admin Panel SPA
├── packages/
│   ├── sdk/           # @cporter/sdk — TypeScript SDK (shared deploy client core)
│   ├── cli/           # @cporter/cli — command-line deploy client
│   ├── mcp/           # @cporter/mcp — MCP server (deploy tools for AI agents)
│   └── github-action/ # composite GitHub Action wrapping the CLI
├── build/            # build-artifact.mjs → bundles FE+BE into a single deployable .zip
├── docs/SPEC.md      # Full technical specification
└── TASKS.md          # Task breakdown by phase
```

## Integrating deployments

The deploy API (`/api/v1`, API-key auth) is wrapped by a layered set of integrations, all
built on one TypeScript core so the contract lives in one place:

| Surface | Package | Use it for |
|---|---|---|
| **CLI** | [`@cporter/cli`](packages/cli) | `npx @cporter/cli deploy ./out.zip …` from any shell or CI |
| **GitHub Action** | [`packages/github-action`](packages/github-action) | one `uses:` step in a workflow |
| **SDK** | [`@cporter/sdk`](packages/sdk) | programmatic deploys from Node/TS |
| **MCP server** | [`@cporter/mcp`](packages/mcp) | let an AI agent deploy / roll back |

Build them all: `pnpm build:packages`. End-user guides live in the app's public **/docs**
area.

On deploy: `apps/web` builds to static → copied into `apps/api/public`; Laravel serves `/api/v1/*` as JSON
and falls back other routes to the SPA. The cPanel docroot points to `cporter.domain/current/public`.

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

## Local testing with Docker Compose

Full stack (PHP 8.3 to mirror cPanel + MySQL 8 + Vite dev + cron worker):

```bash
docker compose up --build          # first run installs composer + pnpm deps (slow)
# open http://localhost:5173  →  log in: admin@cporter.local / password
```

Services: `web` (Vite :5173, proxies /api → api), `api` (Laravel :8000), `worker`
(`schedule:work` — runs Laravel deploy hooks/queue/housekeep), `db` (MySQL, :3307 on host).
Managed sites live in the shared `sites` volume — create projects with **`base_path=/srv/sites/<name>`**
(the dir is auto-created; jail = `/srv/sites`). Tear down with `docker compose down` (add `-v` to wipe data).

## cPanel cron (required for Laravel deploys + housekeeping)

Web PHP can't run shell commands, so a single cPanel cron drives the cron-worker (runs
Laravel hooks, processes staging jobs, cleans up). Add one cron entry:

```cron
* * * * * cd /home/<user>/cporter.domain/current && php artisan schedule:run >> /dev/null 2>&1
```

It fans out (see `apps/api/routes/console.php`) to `cporter:run-jobs` (finalize Laravel
deploys), `queue:work` (artifact extraction), and `cporter:housekeep` (timeout/lock cleanup).

## Documentation

- 🌐 **Live docs:** [cporter.ngocmy.io.vn/docs](https://cporter.ngocmy.io.vn/docs/overview) — user guides (cPanel setup, CLI, GitHub Action, MCP, API) served by cPorter itself
- 📄 [Technical specification (SPEC)](docs/SPEC.md) — architecture & rationale (§20 lists as-built deltas)
- 🔌 [HTTP API contract (`/api/v1`)](docs/API.md) — authoritative interface every client encodes
- 🚀 [Deploying cPorter to cPanel](docs/DEPLOYMENT-CPANEL.md)
- 📦 [Releasing & maintaining the npm packages + GitHub Action](docs/RELEASING.md)
- ✅ [Task breakdown by phase](TASKS.md)

## Status

Phases 0–3 complete (deploy + rollback for static/WordPress/PHP and Laravel via cron-worker, chunked
upload, full admin panel, scheduler, audit log). Phase 4 ecosystem largely shipped: SDK, CLI, GitHub
Action, MCP server, and cPorter self-deploy are live. Remaining: PHP SDK, plugin API, multi-account, plus
the hardening backlog. See [TASKS.md](TASKS.md) and [SPEC §20](docs/SPEC.md#20-as-built-deltas--known-gaps).
