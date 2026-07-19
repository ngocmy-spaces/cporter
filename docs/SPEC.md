# cPorter — Technical Specification

> Status: **as-built v1.0** · Design date: 2026-07-17 · Reconciled with implementation: 2026-07-19
>
> This document describes the architecture, scope, and technical decisions of cPorter. It began as a
> pre-code design draft; it has now been reconciled against the shipped code (Phases 0–3 complete,
> Phase 4 ecosystem largely shipped). **Where the implementation diverged from the original design, the
> deltas are consolidated in [§20 As-built deltas & known gaps](#20-as-built-deltas--known-gaps)** — read
> that section alongside any design section below. For the exact HTTP interface, [docs/API.md](API.md) is
> the authoritative contract; this document is the *design/rationale*.

---

## 1. Overview

**cPorter** is a **self-hosted** deploy orchestration tool that runs as an ordinary web app
on **cPanel shared hosting**, but is capable of **managing and deploying to the folders of other domains**
that reside under the same cPanel account.

Operating model:

- The user has already created addon domains / subdomains on cPanel, each pointing its document root to
  a folder under `/home/<user>/` (e.g. `learn.domain`, `shop.domain`, `api.domain`).
- cPorter installs itself into a dedicated domain (`deploy.domain`) and becomes the "control plane" responsible for:
  receiving artifacts from CI, extracting them, managing releases, atomically swapping the `current` symlink, health checks,
  rollback — for **all** the remaining project domains.
- CI (GitHub Actions / GitLab CI / Jenkins / anything) only needs to **build an artifact and call cPorter's HTTP API**.

### 1.1 Goals

1. **Atomic, zero-downtime** deploys on cPanel **without root, without Docker, without systemd**.
2. Versioned releases with **instant rollback** (symlink swap).
3. API-first: any CI can integrate via HTTP + token.
4. Admin panel for observation & manual operations (dashboard, logs, rollback…).
5. cPorter itself is a **React (FE) + Laravel (BE) monorepo**, deployed as a single folder / single domain.

### 1.2 Non-goals (initial release)

- Does not provision domains / DNS / SSL (the user does this on cPanel).
- Not a CI runner (does not build code — building is CI's job).
- Does not manage multiple cPanel accounts / multiple servers in the MVP (see Roadmap).
- Does not replace the WHM/cPanel API for high-level hosting administration.

---

## 2. cPanel Context & Constraints (MOST IMPORTANT)

This section drives the design. cPanel shared hosting is very different from a VPS/root server.

| Constraint | Design impact |
|---|---|
| PHP runs **under the main cPanel user's privileges** (suEXEC / PHP-FPM per-user) | ✅ cPorter (PHP) **can read/write every folder** under `/home/<user>/` → no root needed to manipulate sibling folders. This is the foundation that makes the idea feasible. |
| `exec`/`proc_open` **CANNOT be used** on this host (tested — §2.1); `system`/`shell_exec`/`passthru` are blocked | ⚠️ **The target app's commands (e.g. `php artisan migrate`) cannot be run from web PHP.** Primary solution: a **cron-worker** running in cron's own shell (not blocked by `disable_functions`) — §9. This is risk #1. |
| `symlink()` and `rename()` (PHP) are usually **enabled** | ✅ Atomic swap of `current` via symlink is feasible (Capistrano/Deployer style). |
| No `tar`/`unzip` via shell (exec is blocked) | ✅ Use **`ZipArchive` (ext-zip, tested at runtime)** to extract, with no shell dependency. Artifact = `.zip` (§2.1). |
| **Inode limit** (file count limit) is very strict on shared hosting | ✅ **Do not** upload `node_modules`; the FE is pre-built into static files in CI. The BE ships with `vendor/` already installed. Limit the number of retained releases (`keep_releases`). |
| **Disk quota** is limited | ✅ Prune old releases; compress artifacts; clean up tmp after extract. |
| Low **max_execution_time / memory_limit / upload_max_filesize** | ⚠️ Uploading a large artifact in a single request easily times out → support **chunked upload** + step-wise extraction (§6). |
| Cron: cPanel allows creating cron jobs (UI or UAPI) | ✅ A single cron calls cPorter's scheduler endpoint (§10). |
| The document root of an addon domain is a **fixed path** once the domain is created | ✅ Point the docroot to `.../current/public` (Laravel) or `.../current` (static); cPanel's Apache defaults to `SymLinksIfOwnerMatch` → same-owner symlinks work. |
| No always-on Redis/systemd/queue worker | ✅ Queue uses the **`database` driver**; the worker runs via cron `queue:work --stop-when-empty`, or synchronous processing for the MVP. |
| The target app's PHP CLI binary may differ from PHP-FPM (multiple EA-PHP versions) | ✅ Configure the **PHP binary path per-project** (e.g. `/opt/cpanel/ea-php82/root/usr/bin/php`). |

> **[CONFIRMED]** All managed projects reside in the **same cPanel account** as cPorter
> (the same `/home/<user>/`). This is single-tenant (finalized in §17.6).

### 2.1 Environment capability profile (tested in practice — 2026-07-17)

> Source: the user probed the target host directly. These are **facts** that govern the entire design below.

- **Runtime:** PHP **8.3**, **LiteSpeed**, single cPanel account.
- **`open_basedir` = OFF** → PHP can read/write all of `/home/<user>/`. ✅ Necessary for the idea, but
  **⚠️ the path jail must be enforced in code ourselves** (security is cPorter's responsibility; there is no OS to enforce it for us).
- **Tested working (runtime):** `mkdir`, `rmdir`, `file_put_contents`, `rename`, `unlink`, `scandir`,
  `is_readable/is_writable/is_dir`, `disk_free_space/disk_total_space`, `ini_get`,
  and the full **`ZipArchive`** (`open`/`addFromString`/`close`/`extractTo`).
- **Functions that exist but are NOT yet runtime-tested (treated as uncertain):** `copy`, `hash_hmac`, `random_bytes`,
  `readlink`, **`symlink`**, `exec`, `proc_open`, `popen`.
- **Available extensions:** zip, curl, json, openssl, phar, mbstring.
- **Definitely blocked:** `system()`, `shell_exec()`, `passthru()`.
- **Decisions from this profile:**
  1. **`exec`/`proc_open` are considered UNUSABLE** → every command that needs a shell (migrate/cache/queue) goes through the
     **cron-worker** (§9), never run directly in a web request.
  2. **Artifact = `.zip`** (using the reliably-tested `ZipArchive`) — **not** tar.gz/PharData.
  3. **`symlink()` must be probed at install time**; there is a **rename-swap fallback** if the host forbids symlinks (§8/§11).
  4. **Upload limits:** `upload_max_filesize` 512MB, **`post_max_size` 256MB** → a single request maxes out at ~256MB;
     beyond that, use **chunked upload** (§6).
  5. **The HTTP client uses cURL** (confirmed) for health checks & outbound webhooks.
  6. **LiteSpeed reads `.htaccess`** (Apache-compatible) → Laravel's `public/` rewrites and the SPA fallback work.

---

## 3. Overall Architecture

```
                    +----------------------+
                    |  CI: GitHub Actions  |
                    |  GitLab / Jenkins…   |
                    +----------+-----------+
                               | HTTPS  (API token / HMAC webhook)
                               v
   ============================ cPorter (deploy.domain) ============================
   |                                                                              |
   |   +------------+     +-------------+     +------------------+                 |
   |   | Deploy API |     | Admin Panel |     | Scheduler (cron) |                 |
   |   |  (Laravel) |     | (React SPA) |     |   endpoint       |                 |
   |   +-----+------+     +------+------+     +---------+--------+                 |
   |         |                   |                     |                          |
   |         +---------+---------+----------+----------+                          |
   |                             v                                                |
   |                     cPorter Core Engine                                      |
   |   +------+ +---------+ +----------+ +---------+ +--------+ +----------+       |
   |   | Auth | | Project | | Artifact | | Release | | Deploy | | Rollback |      |
   |   |      | | Manager | | Manager  | | Manager | | Engine | | Engine   |      |
   |   +------+ +---------+ +----------+ +---------+ +--------+ +----------+       |
   |                             |                                                |
   |                   +---------v----------+                                     |
   |                   | Command Runner     |  (exec | ssh | cron | manual)       |
   |                   +---------+----------+                                     |
   |                             |                                                |
   |                   +---------v----------+                                     |
   |                   | Storage Abstraction|                                     |
   |                   +---------+----------+                                     |
   |                             |                                                |
   |                   +---------v----------+                                     |
   |                   | cPanel FS Adapter  |  (symlink/rename/zip/phar, jailed)  |
   |   ==============================================================             |
   |                             |                                                |
   +-----------------------------|------------------------------------------------+
                                 v
        /home/user/{learn,shop,api}.domain/{current,releases,shared,logs}
```

**Principle**: every filesystem operation goes through the **Storage Abstraction → cPanel FS Adapter**, and
every command that needs a shell goes through the **Command Runner** (multiple drivers, with fallbacks). This makes it easy to test (fake adapter)
and easy to extend to other environments (SSH adapter, S3 artifact store…).

---

## 4. Directory Layout

### 4.1 On cPanel (runtime)

```
/home/user
├── learn.domain/            # a managed project
│   ├── current -> releases/20260717_001   # symlink (atomic swap)
│   ├── releases/
│   │   ├── 20260717_001/                   # an extracted release
│   │   ├── 20260718_001/
│   │   └── …
│   ├── shared/                             # persists across releases
│   │   ├── .env
│   │   └── storage/                        # (Laravel) uploads, cache, logs
│   ├── deploy.lock                         # prevents concurrent deploys
│   └── deploy.log                          # deploy log for this project
│
├── shop.domain/  …
├── api.domain/   …
└── deploy.domain/           # <-- cPorter (also deployed via the same mechanism)
    ├── current -> releases/…
    ├── releases/…
    ├── shared/{.env, storage/, database.sqlite?}
    └── storage/artifacts/                  # where uploaded artifacts are temporarily stored
```

- **`current`**: symlink to the active release. The domain's docroot points to `current/public` (Laravel) or `current` (static).
- **`releases/<id>`**: each release is an immutable folder. `<id>` = `YYYYMMDD_NNN` or timestamp + short SHA.
- **`shared/`**: files/folders that must persist across releases (`.env`, `storage/`, uploads). Releases symlink back into here.
- **`deploy.lock`**: a lock file (containing PID/deploy-id/timestamp) to serialize deploys per-project.
- **`deploy.log`**: a line-based log for each project (in parallel with the log in the DB).

### 4.2 Monorepo source (the cPorter repository)

```
cporter/
├── docs/
│   └── SPEC.md
├── apps/
│   ├── api/                 # Laravel app (Deploy API + Core Engine + Admin API)
│   │   ├── app/
│   │   │   ├── Domain/      # Core Engine: Project, Release, Deploy, Rollback…
│   │   │   ├── Http/        # API controllers, middleware (auth, HMAC)
│   │   │   └── Adapters/    # Storage (cPanel FS), CommandRunner drivers
│   │   ├── database/migrations/
│   │   ├── routes/api.php
│   │   └── composer.json
│   └── web/                 # React + Vite + TS (Admin Panel SPA)
│       ├── src/
│       ├── index.html
│       └── package.json
├── packages/                # ecosystem clients (shipped — see §18, docs/RELEASING.md)
│   ├── sdk/                 # @cporter/sdk — TypeScript client core (the contract lives here)
│   ├── cli/                 # @cporter/cli — command-line client (wraps the SDK)
│   ├── mcp/                 # @cporter/mcp — MCP server for AI agents (wraps the SDK)
│   └── github-action/       # composite GitHub Action (wraps the CLI)
├── build/                   # script that combines the build → a single deployable .zip artifact
│   └── build-artifact.mjs
├── .github/workflows/       # deploy.yml (self-deploy via the Action) + publish.yml (npm release)
├── package.json             # root: pnpm workspace, orchestrates FE+BE build
├── pnpm-workspace.yaml
└── README.md
```

**"One folder, one domain" strategy**: the FE is built into static files and placed into Laravel's `apps/api/public/`.
Laravel serves `/api/*` as a JSON API and falls back every other route to the SPA's `index.html`. The domain docroot
= `deploy.domain/current/public`.

---

## 5. Domain Model (data)

DB: cPanel's MySQL (or SQLite for a small deployment — **[ASSUMPTION B]** use MySQL).

| Entity | Key fields | Notes |
|---|---|---|
| **User** | id, name, email, password, role | Admin panel login. |
| **ApiKey / Token** | id, name, prefix, hash, scopes[], project_id?, last_used_at, expires_at, revoked_at | Token for CI. Stores the **hash** (Sanctum-style), shows plaintext once. Scopes: `deploy`, `read`, `rollback`, `admin`. |
| **Project** | id, name, slug, base_path, type(`laravel`\|`static`\|`php`\|`node`), docroot_subpath, php_binary, keep_releases, health_check_url, hooks(json), shared_paths(json), status | Configuration for one managed domain. |
| **Release** | id, project_id, ref/version, artifact_id, path, state(`pending`\|`extracting`\|`ready`\|`active`\|`superseded`\|`failed`), created_by, activated_at | One physical release. |
| **Artifact** | id, project_id, filename, size, sha256, storage_path, uploaded_at, status | The build file from CI. |
| **Deployment** | id, project_id, release_id, trigger(`api`\|`manual`\|`cron`), status(`queued`→`running`→`success`\|`failed`\|`rolled_back`), steps(json), started_at, finished_at, actor | One pipeline run. |
| **AuditLog** | id, actor, action, subject, meta(json), ip, created_at | Who did what, when. |

Relationships: `Project 1—* Release`, `Release 1—1 Artifact`, `Project 1—* Deployment`, `Deployment *—1 Release`.

---

## 6. Deploy Pipeline (detailed)

Each step is written to `Deployment.steps[]` (name, status, duration, output/tail) and streamed to `deploy.log`.

```
1. Receive Request      POST /api/v1/projects/{slug}/deployments  (metadata: version, sha256, size)
2. Authenticate         Valid token + scope `deploy` + correct project
3. Acquire Lock         Create deploy.lock (atomic O_EXCL). If already locked → 409 Conflict (or queue)
4. Upload Artifact      Receive the .zip file (single ≤256MB, or chunked). Store into storage/artifacts/<uuid>.zip
5. Verify Hash          Compute sha256 server-side == sha256 sent by CI. Mismatch → abort + unlock
6. Prepare Release Dir  Create releases/<id>/
7. Extract              ZipArchive::extractTo() into the release dir. Guard against Zip-Slip. Check inode/size cap
8. Link Shared          symlink .env, storage/… from shared/ into the release. Seed shared/ from the artifact if it shipped the path; else create a `dir`, but for a `file` entry (e.g. .env) fail loudly rather than create an empty one
9. Validate             Check the required structure (e.g. public/index.php, index.html… exist)
10. Pre-activate Hooks  (Laravel) migrate, config:cache… → enqueue to cron-worker (§9). static/WP/PHP: skip
11. Activate Release    atomic symlink swap: create current.tmp -> releases/<id> then rename → current
12. Post-activate Hooks (Laravel) queue:restart, opcache reset… → cron-worker. static/WP/PHP: skip
13. Health Check        GET health_check_url (cURL); expect 2xx within N seconds. Fail → auto-rollback (§8)
14. Prune               Delete old releases beyond keep_releases
15. Success             Release.state=active, Deployment.status=success (Laravel: after the cron hooks finish)
16. Release Lock        Delete deploy.lock (even on failure — finally)
```

**Chunked upload** (when the artifact > `post_max_size` 256MB — §2.1):
- `POST …/artifacts` → initialize an upload session (returns `upload_id`).
- `PUT …/artifacts/{upload_id}/chunks/{n}` → send each part.
- `POST …/artifacts/{upload_id}/complete` → assemble + verify sha256.

**Idempotency**: the `Idempotency-Key` header lets CI retry safely (without creating a duplicate deployment).

**Error handling**: if any of steps 6–13 fails → stop, do NOT swap `current` (unless already at step 11+), record the error,
auto-rollback if already activated, and always `finally { unlock }`.

---

## 7. Deploy API (proposed endpoints)

> **As-built:** the authoritative, current contract is **[docs/API.md](API.md)** — including the two auth
> surfaces (API key vs admin session), the real **project-nested** chunked-upload paths, and known gaps
> (`/deployments/{id}/logs` not implemented — logs live in `steps[]`; `GET /projects` + `/releases` are
> admin-session only; no `Location` header; `meta` not emitted). The table below is the original design
> sketch; where it disagrees with API.md, API.md wins. See §20.

Base: `https://deploy.domain/api/v1` · Auth: `Authorization: Bearer <token>`

| Method | Path | Description |
|---|---|---|
| POST | `/projects/{slug}/deployments` | Create & run a deployment (with or without an artifact) |
| POST | `/projects/{slug}/artifacts` | Initialize an upload (chunked) |
| PUT | `/artifacts/{uploadId}/chunks/{n}` | Upload a chunk |
| POST | `/artifacts/{uploadId}/complete` | Finalize + verify |
| GET | `/projects/{slug}/deployments/{id}` | Status + steps (poll) |
| GET | `/projects/{slug}/deployments/{id}/logs` | Stream/tail the log |
| POST | `/projects/{slug}/rollback` | Roll back to the previous release / a specified release |
| GET | `/projects/{slug}/releases` | List releases |
| GET | `/projects` | List projects (read scope) |
| POST | `/webhooks/{provider}` | Webhook (GitHub/GitLab) — verify HMAC signature |

**Standard response**: JSON `{ data, meta, error }`. A deployment returns `202 Accepted` + `Location` to poll.

---

## 8. Rollback Engine

- **Fast (default)**: swap `current` back to the previous release (the most recent `superseded`) or a specified release →
  atomic symlink swap (create `current.tmp` then `rename`). Run post-activate hooks (cache clear). Health check.
- **Swap mechanism & fallback**: the standard mechanism is **symlink swap** (`symlink()` + `rename()`). If the probe
  detects that the host **forbids symlinks**, fall back to **rename-swap of the folder**: `rename(current → current.old)` then
  `rename(releases/<id> → current)` (there is a very brief gap, acceptable for hosts that do not support symlinks;
  releases still retain copies for rollback).
- **Migration**: do NOT run `migrate:rollback` automatically by default (data risk). Only surface a warning &
  allow a manual hook. → **[ASSUMPTION C]** rollback = code-only.
- **Auto-rollback**: if the Health Check step (13) fails after activation → automatically swap the symlink back to the old release,
  marking the deployment `rolled_back`.

---

## 9. Command Execution Strategy (risk #1 — direction finalized)

**Problem:** web PHP (LiteSpeed FPM) on this host **lacks `exec`/`proc_open`** (§2.1) → it cannot
call `php artisan migrate` or any of the target app's shell commands inside an HTTP request.

**Key insight:** a **cPanel cron job runs in its own shell spawned by `crond`**, NOT constrained by
PHP-FPM's `disable_functions`. The cron command line is executed directly by `/bin/sh`, so
`php artisan …` runs normally. → cPorter splits into **two execution contexts**:

| Context | What it can do | Used for |
|---|---|---|
| **Web PHP (synchronous)** | filesystem (mkdir/rename/unlink/scandir), `ZipArchive` extract, `symlink`, cURL health check | The entire pipeline for **static / WordPress / plain PHP** apps: extract → link shared → swap symlink → health check → prune. **No shell needed.** |
| **Cron shell (asynchronous)** | run real shell commands: `php artisan migrate/config:cache/queue:restart`, composer if needed, cPorter's own artisan | **Hooks for the target Laravel app** + queue worker + housekeeping |

### 9.1 Command Runner mechanism (`cron-worker` driver)

```
Web request (deploy Laravel)                 Standing cron (every ~1 minute)
──────────────────────────                   ─────────────────────────────
1. extract + link + swap symlink   ┐         a. crond runs: php <cporter>/current/artisan cporter:run-jobs
2. enqueue "shell jobs":           │            (this command line is run by the shell → does NOT need exec in PHP)
   - php <target>/current/artisan  │         b. the runner reads pending jobs from the DB
     migrate --force               │──────►  c. executes each job via shell (through the cron command itself,
   - artisan config:cache          │            not through PHP's exec())
3. Deployment.status =             │         d. writes the result (exit code, output) back to Job + Deployment
   "activated, hooks_pending"      ┘         e. re-runs the health check → success | failed(+auto-rollback)
```

- **Job queue** stored in the DB (cPorter's own `jobs` table, or Laravel's `database` queue driver).
- **Runner** = cPorter's own `cporter:run-jobs` artisan command, invoked by cron. Because the cron command
  is run by the shell, the runner can use the shell to execute the target app's commands **even when PHP `exec` is blocked** —
  the safest approach: **each job is a shell command line that the runner writes out and lets the next cron run**, or
  the runner tries `proc_open` (CLI PHP is usually less restricted than FPM — probe CLI context separately).
- **Latency:** hooks run within ≤ one cron cycle (recommend 1 minute; you can have 1 cron/minute running continuously).
  For apps **with no hooks** (static/WP/PHP) → deploy is **instant, synchronous**, never touching cron.

### 9.2 The drivers (in priority order on this host)

| Driver | Status on this host | Notes |
|---|---|---|
| `none` | ✅ default for static/WP/PHP | No shell commands needed; everything runs in web PHP. |
| `cron-worker` | ✅ **primary** for Laravel | Runs hooks via the cron shell context. Asynchronous. |
| `proc_open` (CLI) | ⚙️ probe | If CLI PHP allows `proc_open`, the runner runs more synchronously within cron. |
| `ssh` (phpseclib) | ❔ only if the host enables SSH | Not confirmed on this host; kept as an option. |
| `manual` | ✅ last-resort fallback | Marks `hooks_pending(manual)`, shows the commands to run in the Admin panel for the user to run themselves. |

### 9.3 Capability probe (at install & periodically)

cPorter runs a probe and stores it in Settings, displaying it in the Admin panel: is `symlink` runtime OK?, is `ZipArchive` OK?,
`proc_open` (web & CLI) OK?, write permission on each `base_path`, disk space, PHP version, whether cron is configured.

> **Roadmap consequence:** because static/WP/PHP can be deployed **entirely without a shell**, the MVP (Phase 1) does this group
> first; Laravel + cron-worker come in Phase 2 (§18).

---

## 10. Scheduler / Cron

- **One** cPanel cron job (e.g. every minute) calls `GET/POST https://deploy.domain/cron/tick` (internal token).
- `cron/tick` handles: running `queue:work --stop-when-empty` (if using the DB queue), pruning expired releases,
  timing out stuck deployments (cleaning up stale locks), retrying health checks on schedule, running scheduled tasks.
- The cron can be created automatically via the **cPanel UAPI** at setup (if available), or via manual instructions.

---

## 11. Storage Abstraction & cPanel FS Adapter

Interface (conceptual):

```
StorageAdapter:
  putArtifact(stream) : path
  extract(archive, destDir)        # PharData/ZipArchive, guard against zip-slip
  symlinkAtomic(target, linkPath)  # ln -sfn = symlink tmp + rename
  linkShared(release, sharedPaths)
  pruneReleases(project, keep)
  writeLock(project) / removeLock(project)
  readCurrentTarget(project)
```

**Jail / path security**: every operation is only permitted within the `allowed_base_paths` list
(the `Project.base_path` entries + cPorter's own storage). Normalize with `realpath` and reject anything that escapes the jail
(guards against path traversal, symlink escape).

The adapter structure allows adding later: `SshStorageAdapter`, `S3ArtifactStore`…

---

## 12. Security

- **Token/API Key**: randomly generated, stores the **hash** (never plaintext), shown once. Has a prefix for lookup.
- **Scopes**: `read`, `deploy`, `rollback`, `admin`; scoped per project.
- **Webhook**: verify the HMAC signature per provider (GitHub `X-Hub-Signature-256`…).
- **Rate limit** + optional **IP allowlist** for the deploy API.
- **Zip-Slip / Path traversal**: validate every entry during extraction; jail the paths (§11).
- **Inode/size cap**: reject artifacts exceeding the file-count/size threshold.
- **Audit log** for every sensitive action (deploy, rollback, token creation/revocation).
- **Admin auth**: session + password hash (bcrypt/argon2); optional 2FA (roadmap).
- **HTTPS required** (cPanel AutoSSL / Let's Encrypt configured by the user).
- **`.env` & secrets** kept in `shared/`, not inside the artifact, never logged.

---

## 13. Admin Panel (React SPA)

| Screen | Content |
|---|---|
| **Dashboard** | Project overview, recent deploys, success rate, active releases, alerts (pending migration, stuck lock). |
| **Projects** | CRUD projects (base_path, type, docroot, php_binary, hooks, health check, keep_releases). "Deploy"/"Rollback" buttons. |
| **Deployments** | List + realtime status (poll), step timeline, log tail. |
| **Releases** | Release history per project; activate (rollback) a release; view version diff. |
| **Logs** | Centralized logs (deploy + audit), filter by project/status/time. |
| **Settings** | Capability probe (exec/ssh/zip…), global configuration, cron status. |
| **Users** | Manage admin users, roles. |
| **API Keys / Tokens** | Create/revoke tokens, assign scopes & projects, view last_used. |

The FE calls the same `/api/v1` API (using a session or an admin token). Realtime: **polling** for the MVP
(SSE/WebSocket is hard on shared hosting) — poll `deployments/{id}` every 1–2s while running.

---

## 14. Deploying cPorter itself (self-hosting)

- cPorter is Laravel + React → it uses the exact same release/symlink mechanism.
- **First bootstrap**: install manually (upload the first build, create `.env` in `shared/`, run migrate,
  set the docroot to `deploy.domain/current/public`). This is chicken-and-egg, so step 0 is done by hand.
- Afterward: cPorter can **self-deploy** via its own API (a special `self` project) — with caution,
  done in a later phase.
- Sample CI (`.github/workflows/deploy.yml`) builds the artifact then calls the API.

**Building the cPorter artifact**:
1. `apps/web`: `pnpm build` → copy `dist/` into `apps/api/public/`.
2. `apps/api`: `composer install --no-dev --optimize-autoloader`.
3. Package `apps/api/` (now containing `public/` + `vendor/`) into a **`.zip`**, compute sha256.
4. Upload + deploy via the API.

---

## 15. Tech Stack (proposed)

| Layer | Choice | Reason |
|---|---|---|
| BE | **Laravel 12, PHP 8.2+** | Laravel was chosen; good ecosystem, Artisan, migrations. |
| Auth API | **Laravel Sanctum** | Hashed tokens, scopes, simple. |
| Queue | **database driver** + cron worker | Shared hosting usually has no Redis. |
| DB | **MySQL (cPanel)** | Available on cPanel. |
| FE | **React 19 + Vite + TypeScript** | React was chosen; Vite builds fast and produces static output. |
| FE UI kit | **Mantine v9** (core + hooks) + `@tabler/icons-react` + PostCSS preset | The only UI kit — **no Tailwind**. Docs consumed via `llms.txt` (see [docs/FRONTEND.md](FRONTEND.md) + the `mantine-ui` skill). |
| FE state/data | React Query 5 + React Router 7 | Common standard, easy to maintain. |
| Artifact | **ZIP** (`ZipArchive`) | Tested working at runtime (§2.1). Does not use tar.gz/PharData. |
| Command exec | **cron-worker** (artisan `cporter:run-jobs` run via cron) | exec/proc_open are unusable in web PHP (§9). |
| SSH (optional) | phpseclib | The `ssh` driver — only if the host enables SSH (not confirmed on the current host). |
| Test | Pest/PHPUnit (BE), Vitest (FE) | There is a **FakeStorageAdapter** to test the pipeline without touching the real FS. |

---

## 16. Project Configuration (example)

```jsonc
{
  "name": "Learn Platform",
  "slug": "learn",
  "base_path": "/home/user/learn.domain",
  "type": "laravel",                 // laravel | static | php | node
  "docroot_subpath": "public",       // current/public
  "php_binary": "/opt/cpanel/ea-php82/root/usr/bin/php",
  "keep_releases": 5,
  "shared_paths": [                        // each entry: {path, type}; type = "file" | "dir"
    { "path": ".env", "type": "file" },    // "file" → must be created in shared/ first (never auto-created empty)
    { "path": "storage", "type": "dir" }   // "dir"  → auto-created if missing
  ],                                        // bare strings (e.g. ".env") still accepted → normalized to type "dir"
  "health_check_url": "https://learn.domain/up",
  "hooks": {
    "pre_activate":  ["artisan migrate --force", "artisan config:cache"],
    "post_activate": ["artisan queue:restart"]
  }
}
```

- `type: static` → skip hooks/command runner, only extract + swap symlink (the safest on shared hosting).

---

## 17. Decisions Made & Open Questions

### 17.1 Finalized (2026-07-17)

| # | Decision | Resolution |
|---|---|---|
| 1 | Command exec for the target app | **No exec/proc_open** in web PHP → use the **cron-worker** (§9). Static/rollback need no shell. |
| 2 | Account scope | **Single-tenant, same cPanel account.** Multi-tenant → roadmap Phase 4. |
| 3 | Database | **MySQL (cPanel).** |
| 4 | App types in the MVP | **static/React SPA, Laravel, WordPress/plain PHP.** (Node/Passenger → later phase.) |
| 5 | Artifact format | **ZIP** (§2.1). |

### 17.2 Remaining — should be clarified before/at coding time

1. **Actual artifact size** — how many MB is expected? < 200MB → single upload for the MVP, chunked in Phase 2;
   or > 256MB → chunked needed right away.
2. **WordPress**: deploy only code (theme/plugin/`wp-content`) or the core too? shared_paths for WP
   (`wp-content/uploads`, `wp-config.php`) — confirm to define `type: wordpress` correctly.
3. **Health check URL** for each app type: Laravel 12 has `/up` built in; what URL do static/WP need?
4. **Cron interval** acceptable for Laravel hooks (1 minute?) — affects Laravel deploy latency.
5. **First CI integration** (GitHub Actions?) to make a sample workflow + SDK first.

---

## 18. Phased Roadmap

**Phase 0 — Foundation** — ✅ **done**
- Scaffold the monorepo (Laravel + React + build script + sample CI).
- Domain model + migrations. Auth (Sanctum) + API key. Capability probe.

**Phase 1 — MVP Deploy (static/SPA first)** — ✅ **done**
- Storage Abstraction + cPanel FS Adapter (symlink, extract, prune, lock).
- Full pipeline for `type: static`: upload → verify → extract → activate → health check → prune.
- Rollback (symlink). Admin: basic Projects, Deployments, Releases, Logs.

**Phase 2 — Laravel target + Hooks** — ✅ **done**
- Command Runner (proc_open via cron-worker) + hooks (migrate/cache/queue).
- Chunked upload. Idempotency. Auto-rollback.

**Phase 3 — Complete Admin & Scheduler** — ✅ **done**
- Dashboard, Users, Tokens UI. Cron scheduler (queue worker, prune, timeout). Audit log UI.

**Phase 4 — Ecosystem** — 🟡 **largely shipped**
- ✅ **Shipped:** JS SDK (`@cporter/sdk`), CLI (`@cporter/cli`), GitHub Action
  (`packages/github-action`, floating tag `@v1`), **MCP server** (`@cporter/mcp` — not in the original
  design, added in practice), and **cPorter self-deploy** (`deploy.yml` deploys `project: cporter` on
  push to `main`). Release process: [docs/RELEASING.md](RELEASING.md).
- ⬜ **Not yet built:** PHP SDK, Plugin/Adapter API, multi-server / multi-account.
- ⚠️ **`@cporter/mcp` is not yet in the npm publish pipeline** (`publish.yml` ships sdk + cli only) — see §20.

---

## 19. Risks & Mitigations (summary)

| Risk | Level | Mitigation |
|---|---|---|
| `exec` blocked → cannot migrate | High | Multi-driver Command Runner + manual fallback; prioritize static first (§9) |
| Timeout when uploading/extracting a large artifact | Medium | Chunked upload, step-wise extraction, raise limits via `.htaccess`/php.ini if allowed |
| Inode/disk quota full | Medium | Do not ship node_modules, prune releases, cap file count |
| Symlink not allowed/not followed at the docroot | Medium→Low | Probe at setup; fall back to copy-swap if the host forbids symlinks |
| Concurrent deploy / stuck lock | Medium | Atomic O_EXCL lock + TTL, cron cleans up expired locks |
| Rollback corrupts data due to migration | High | Rollback is code-only by default, with a clear warning (§8) |

---

## 20. As-built deltas & known gaps

> Consolidated on 2026-07-19 by reconciling this spec against the shipped code. This is the **single place**
> to look for "where does reality differ from the design above". Two kinds of entry:
> **(D) Documented delta** — code intentionally diverged; the spec section above is now updated/annotated.
> **(B) Backlog** — a real gap worth fixing; tracked in [TASKS.md](../TASKS.md) "Phase 5 — Hardening".
> When a (B) item is fixed, delete its row here and drop the ⚠️ note in the referenced section / API.md.

### 20.1 API surface (§7 / API.md)
| Kind | Item | Reality |
|---|---|---|
| D | Chunked-upload paths | Project-nested: `POST/PUT …/projects/{slug}/artifacts/uploads/…`, not the top-level shape sketched in §7. API.md is authoritative. |
| D | Envelope | Success `{data}`, error `{error}`. `meta` is reserved, **not emitted**. |
| B | `GET /projects` & `GET /projects/{slug}/releases` | Listed in §7 as CI read-scope, but implemented **admin-session-only** → CI read-scope tokens can't call them. Decide: expose to read-scope keys, or drop from the CI contract. |
| B | `GET /projects/{slug}/deployments/{id}/logs` | In §7, **not implemented**. Logs are returned inside the deployment `steps[]`; either build the endpoint or remove it from the contract (currently removed from API.md). |
| B | `202` has no `Location` header | §7/§6 promise `Location`; not set. Poll via the id in the body. |

### 20.2 Deploy pipeline & concurrency (§6)
| Kind | Item | Reality |
|---|---|---|
| B | Lock ordering / no `409` | Upload + sha256 verify happen in the web request **before** the deploy lock (lock is acquired inside the async job). Concurrent deploys → the loser **fails asynchronously**, not a synchronous `409 Conflict`. |
| B | Step 9 "Validate" is weak | Only checks the docroot subpath is a directory; does **not** verify `public/index.php` / `index.html` exist as §6 step 9 states. |
| D | Steps 5–6 placement | Hash verify + release-dir creation happen in the controller / folded into extract, not as discrete recorded `steps[]` entries. Functionally equivalent. |

### 20.3 Rollback engine (§8)
| Kind | Item | Reality |
|---|---|---|
| B | Rollback runs **only** the symlink swap | It **skips post-activate hooks (cache clear) and the health-check re-run** that §8 specifies. Risk: rolling back to a release that is itself unhealthy goes unnoticed, and Laravel caches aren't refreshed. **Highest-value backlog item.** |
| D | Symlink-forbidden fallback | Implemented as **copy-swap** (copy release into `current`, keep `releases/<id>` immutable), not the rename-swap described in §8. Behaviour is equivalent and safe. |

### 20.4 Command execution (§9)
| Kind | Item | Reality |
|---|---|---|
| D | No shell-jobs table | Hooks for Laravel run **inline via `proc_open`** inside the cron `run-jobs` finalize step, not as individually enqueued shell-command jobs. Simpler; works because CLI/cron PHP allows `proc_open`. |
| D | Driver matrix collapsed | Only `ProcessCommandRunner` (proc_open) is wired. `manual` = "shell unavailable" message. `command_driver` config is **informational only**; **no SSH driver**. |
| D | No `/cron/tick` HTTP endpoint (§10) | One cPanel cron runs `php artisan schedule:run` (shell context), which drives `cporter:run-jobs` + `queue:work` + `cporter:housekeep`. The `cron_token` config is currently **unused**. (Arguably better — shell context isn't blocked by `disable_functions`.) |

### 20.5 Domain model (§5)
| Kind | Item | Reality |
|---|---|---|
| D | Enums grew | `Deployment.status` adds `hooks_pending`; `Deployment.trigger` adds `webhook`; `Project.type` adds `wordpress`. |
| D | Persisted fields | `Deployment.idempotency_key` is a real column with a unique `(project_id, idempotency_key)` index; a `settings` key/value table exists (probe/config). |
| D | `Release`→`Artifact` | `artifact_id` is **nullable** (effectively `*—1 optional`, not strict `1—1`). |

### 20.6 Ecosystem / release (§18)
| Kind | Item | Reality |
|---|---|---|
| B | `@cporter/mcp` not published | Shipped as a package with a README + a `/docs` page, but **not in `publish.yml`** (which releases sdk + cli only). Decide whether to publish it to npm; if yes, add it to the publish matrix + RELEASING.md "What ships". |

### 20.7 Reality exceeds the spec (no action — worth noting)
- Uncompressed-size **zip-bomb guard** on extract (beyond §6's inode/size cap).
- Deploy lock has a **TTL steal** for stale locks.
- **Idempotency** is fully implemented (§6/§7 only mentioned the header).
- Path jail is thorough (lexical normalize + `realpath` ancestor resolution + null-byte reject).
