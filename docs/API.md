# cPorter HTTP API — `/api/v1` Contract Reference

> Status: **as-built v1.0** · Date: 2026-07-19
>
> This is the **authoritative contract** for cPorter's HTTP API. Every client — `@cporter/sdk`,
> `@cporter/cli`, the GitHub Action, and `@cporter/mcp` — encodes this contract, so it must be kept
> in sync with `apps/api/routes/api.php` and the controllers under `apps/api/app/Http/Controllers/Api`.
> Design rationale (why the pipeline works this way on cPanel) lives in [SPEC.md](SPEC.md); this doc is
> the *interface*, not the *design*.

---

## 1. Base URL & versioning

```
https://<deploy-host>/api/v1
```

All routes are under the `v1` prefix (`Route::prefix('v1')`). There is no other version yet.

## 2. Two auth surfaces

cPorter exposes the same API to two distinct callers:

| Surface | Who | Auth | Notes |
|---|---|---|---|
| **CI-facing** | GitHub Actions / CLI / SDK / MCP | `Authorization: Bearer cpk_…` (API key) | Stateless. Scoped + optionally project-bound. |
| **Admin (browser)** | the React SPA | same-origin **session** (`web` guard) + CSRF | Cookie session. Role `admin` or `viewer`. Not for CI. |

### 2.1 API keys (CI)

- Header: `Authorization: Bearer cpk_<random>`. The server stores only a `sha256` hash + a lookup prefix.
- **Scopes:** `read`, `deploy`, `rollback`, `admin`. `admin` is a super-scope (satisfies any check).
- **Project binding:** a key may be bound to one `project_id`; if bound, it can only act on that project
  (`guardProjectScope()`), returning `403` otherwise.

### 2.2 Admin session (SPA)

- `GET /csrf` primes the `XSRF-TOKEN` cookie, then `POST /auth/login` opens a session.
- Reads are allowed for `admin` + `viewer`; writes are gated by `role:admin`.
- `/api/*` returns JSON `401` (never an HTML login redirect) when unauthenticated.

## 3. Response envelope

- **Success:** `{ "data": … }`
- **Error:** `{ "error": "<message>" }` with the appropriate HTTP status.
- `meta` is **reserved but not currently emitted** (the original SPEC §7 envelope was `{data, meta, error}`;
  `meta` is not wired yet — do not depend on it).

Common statuses: `200` ok · `202` accepted (deployment queued) · `401` unauth · `403` wrong scope/project ·
`404` unknown project/deployment · `409` — *see the concurrency note in §7* · `422` validation · `503` webhook secret unset.

---

## 4. CI-facing endpoints (API key)

### `GET /whoami`  — scope: *any valid key*
Verify a token and inspect its binding.
```json
{ "data": { "name": "ci-deploy", "scopes": ["deploy","read"], "project_id": 2 } }
```

### `POST /projects/{slug}/deployments`  — scope: `deploy`
Create + run a deployment from a **single-request** upload.
- **Body:** `multipart/form-data` with the artifact `.zip` (single upload ≤ `post_max_size`, ~256MB on the target host).
- **Fields:** `version` (optional release label), `sha256` (client-computed, verified server-side — mismatch aborts).
- **Headers:** `Idempotency-Key` (optional; see §6).
- **Returns:** `202 Accepted` with `{ "data": <Deployment> }`. Poll the deployment for progress.
  > ⚠️ No `Location` header is set on the 202 (SPEC §7 mentions one; not implemented). Poll via the id in `data`.
  > A **disabled** project (`status: disabled`) rejects new deploys with `409 Conflict` (also on the chunked `uploads` init/complete).
  > If another deploy is already in progress for the project, this one is **accepted and queued** (`status: queued`) and runs when the project frees — see §7.

### Chunked upload — scope: `deploy`
For artifacts larger than a single request. Three steps, **all project-nested**:

1. `POST /projects/{slug}/artifacts/uploads` → `{ "data": { "upload_id": "…", … } }`
2. `PUT  /projects/{slug}/artifacts/uploads/{uploadId}/chunks/{index}` — raw body per chunk (`{index}` is numeric, 0-based)
3. `POST /projects/{slug}/artifacts/uploads/{uploadId}/complete` → assembles, verifies sha256, then deploys → `202`

> ⚠️ These paths differ from the original SPEC §7 (which showed top-level `/artifacts/{uploadId}/…`).
> The **project-nested paths above are the real contract** — the SDK uses them.

### `GET /projects/{slug}/deployments/{id}`  — scope: `read`
Poll status + step timeline of one deployment. Returns `{ "data": <Deployment> }` (includes `steps[]`).
Logs are delivered **inside `steps[]`** — there is no separate `/logs` endpoint (see §8).

### `GET /projects/{slug}/releases`  — scope: `read` (or admin session)
List the project's re-activatable releases (live + prior, still on disk), newest first, bounded by `keep_releases`. Dual-auth (T5.4): reachable with a **read-scope API key** or the **admin session**; a project-scoped key sees only its own project (else `403`). Returns `{ "data": [<Release>] }`.

### `POST /projects/{slug}/rollback`  — scope: `rollback`
Roll the project back. Body: `{ "release_id": <int> }` (optional — omit to roll back to the previous release; the SDK/MCP option `releaseId` maps to this snake_case wire field).
Returns `{ "data": <Deployment> }`.
> Behavior: **code-only symlink swap by design** — no hooks, no inline health re-check (an operator wants the previous code live immediately; post-rollback health is observed via continuous monitoring). SPEC §21.3.

### `POST /webhooks/{provider}`  — signature-verified, no token/session
`{provider}` ∈ `github | gitlab`.
- **GitHub:** HMAC-SHA256 over the raw body, compared against `X-Hub-Signature-256` (`hash_equals`).
- **GitLab:** constant-time compare of `X-Gitlab-Token`.
- Secret from `CPORTER_WEBHOOK_SECRET`; returns `503` if unset.

---

## 5. Admin endpoints (browser session)

Used only by the SPA. **Not reachable with an API key** (session guard). Listed here so the contract is complete.

| Method | Path | Role | Purpose |
|---|---|---|---|
| GET | `/health` | public | liveness (`{status,service,time}`) |
| GET | `/csrf` | public | prime XSRF-TOKEN cookie |
| POST | `/auth/login` · `/auth/logout` · GET `/auth/user` | session | login flow |
| GET | `/system/capabilities` | read | capability probe result |
| POST | `/system/capabilities/refresh` | admin | re-run the probe |
| GET | `/system/cron` | read | cron liveness + cadence mode (heartbeat) |
| GET | `/system/storage` | read | artifact-store health from the last housekeep sweep: `{ state: healthy\|warning\|unknown, store_bytes, unpruned_count, reclaimed_count, freed_bytes, last_run_at, prune_enabled, warn_bytes, warnings[] }` (warning codes: `pruning_disabled`, `store_over_threshold`, `sweep_stale`) |
| GET/POST/DELETE | `/api-keys` · `/api-keys/{id}` | read / admin | token CRUD (plaintext shown once) |
| GET | `/projects` · `/projects/{slug}` | read | list / show projects. `?search=` + `?status=active\|disabled\|deleting` filter; `?page=`/`?per_page=` (≤100) switch to a paginated `{data, meta}` envelope, else the full list. **Show** also embeds `active_release` (`{id,version,activated_at}`) and `last_deployment` (`{id,status,created_at}`) summaries (or `null`) so the detail page can lazy-load the per-tab lists |
| POST | `/projects` | admin | create a project (jail-validated `base_path`, which must not already be used by another project). Optional `auto_rollback_on` (array of trigger keys — `health_check`, `post_activate_hook`; empty/omitted = auto-rollback off). Also scaffolds `releases/` + `shared/` and returns a `preflight` report alongside `data` (see below) |
| POST | `/projects/{slug}/preflight` | admin | (re-)run host preflight: idempotently ensure `releases/` + `shared/`, probe symlink support, inspect `current`, flag missing shared files + the manual Document-Root step → `{ data: <report> }` |
| POST | `/projects/{slug}/clone` | admin | duplicate the project's config into a new project. Body: `name`, `base_path` (required, jail-validated + unique), `slug?`. Copies type/docroot/keep_releases/auto_rollback_on/health_check_url/shared_paths/hooks/env_vars; **no** releases/deployments are copied; the clone starts `active`. Same `{ data, preflight }` + `201` as create |
| PATCH | `/projects/{slug}` | admin | update project config; `status: disabled` blocks new deploys. `slug`/`base_path`/`type` are frozen once releases exist. `keep_releases` (≥1) is applied immediately — lowering it prunes surplus releases at once but never deletes the currently-live release, so the list may briefly hold more than `keep_releases` (SPEC §21.4). `auto_rollback_on` (array of `health_check`/`post_activate_hook`) sets the per-trigger rollback policy |
| DELETE | `/projects/{slug}` | admin | soft-delete a project. Body `purge`: `none` (default — hide only, files kept, `200`) · `releases` (drop releases/ + `current`, keep shared/) · `all` (delete the whole base_path). A purge runs async: the project goes `deleting` (deploys blocked) and is hidden when done → `202` |
| GET | `/projects/{slug}/env` | admin | read managed env vars (decrypted) + the `shared/.env` file state → `{ data: { vars, file } }`. **Admin-only** (secrets) — never in `show`/`index` |
| PUT | `/projects/{slug}/env` | admin | replace the managed env vars. Body `env_vars: [{key,value}]` (keys `^[A-Za-z_][A-Za-z0-9_]*$`, unique, ≤255; value ≤32768). Stored encrypted; rendered to `shared/.env` on the next deploy → `{ data: { vars, file } }` |
| POST | `/projects/{slug}/env/adopt` | admin | force-write `shared/.env` from the current env vars, taking over a hand-created (unmanaged) file (stamps the managed marker). Does not redeploy → `{ data: { vars, file }, message }` |
| POST | `/projects/{slug}/disk-usage/recompute` | admin | recompute the on-disk footprint off-request; returns the project and sets `disk_usage_status: running` to poll. Idempotent while a run is in flight (unless >10 min stale) → `202` |
| GET | `/projects/{slug}/deployments` · `/projects/{slug}/releases` | read | per-project history. `releases` returns only re-activatable releases (`active`/`superseded`, still on disk) — bounded by `keep_releases`; `pruned`/`failed` ones are omitted (full history is in `deployments`) |
| GET | `/projects/{slug}/activity` | read | project-scoped audit feed (create/update/preflight/delete…), newest first, `?action=` filter, capped at 200 |
| GET | `/deployments` · `/deployments/{id}` | read | recent + detail |
| POST | `/releases/{id}/activate` | admin | activate a release (rollback-from-UI) |
| GET | `/audit-logs` | read | audit trail |
| GET/POST/DELETE | `/users` · `/users/{id}` | admin | admin-user management |

> ⚠️ **Contract gap to know:** SPEC §7 listed `GET /projects` and `GET /projects/{slug}/releases` as CI
> *read-scope* endpoints, but they are implemented **admin-session-only**. A read-scope API key cannot list
> projects or releases today. If CI needs this, it's a backlog item (SPEC §20).

### Preflight report

`POST /projects` (as `preflight`) and `POST /projects/{slug}/preflight` (as `data`) return:

```json
{
  "ok": true,
  "base_path": "/home/user/site",
  "checks": [
    { "key": "base_path",    "label": "Project base directory", "status": "ok",      "detail": "…" },
    { "key": "releases",     "label": "releases/ …",            "status": "created", "detail": "…" },
    { "key": "shared",       "label": "shared/ …",              "status": "created", "detail": "…" },
    { "key": "symlink",      "label": "Symlink support",        "status": "ok",      "detail": "…" },
    { "key": "current",      "label": "current symlink",        "status": "pending", "detail": "…" },
    { "key": "shared_files", "label": "Shared files",           "status": "warning", "detail": "…" },
    { "key": "docroot",      "label": "Document Root",          "status": "manual",  "detail": "…" }
  ]
}
```

`status` ∈ `ok | created | pending | warning | error | manual`. `ok` (top-level) is `true` when no check
is `error`; `warning`/`manual`/`pending` do not fail it. cPorter **creates** `base_path`, `releases/`,
`shared/` (idempotent — never overwrites) but **never** creates `current` (`pending` until the first
deploy) nor the domain's Document Root (`manual` — a cPanel vhost concern). A shared entry of type `file`
absent from `shared/` is a `warning`: create it by hand or the deploy fails at `link_shared`.

### Project disk fields

The project object carries an on-disk footprint, refreshed by the deploy pipeline and by the recompute
endpoint above (bytes; symlinks are never followed, so shared data is counted once):

| Field | Meaning |
|---|---|
| `disk_usage` | live footprint — active release (`current`) + `shared/` |
| `releases_disk_usage` | all retained release directories (rollback history) |
| `disk_usage_status` | `idle` \| `running` (a recompute is in flight) |
| `disk_usage_calculated_at` | when the figures were last computed (`null` if never) |
| `shared_disk_usage` | per-shared-path bytes, keyed by the entry's relative path; `null` until first computed |

```json
"shared_disk_usage": { "storage": 20480, ".env": 512, "public/uploads": 1048576 }
```

### Project hooks

`hooks` (accepted by `POST /projects` and `PATCH /projects/{slug}`) is an object keyed by deploy stage →
ordered list of shell-command strings. Exactly two stages are recognised; any other key is rejected `422`:

| Stage | When it runs | Failure |
|---|---|---|
| `pre_activate` | before the new release goes live | deploy fails; nothing is swapped |
| `post_activate` | after activation | deploy `failed` + project `unhealthy`; rolled back only if `post_activate_hook` ∈ `auto_rollback_on` (SPEC §21.2) |

```json
"hooks": { "pre_activate": ["php artisan migrate --force"], "post_activate": ["php artisan queue:restart"] }
```

Each command runs **verbatim** as a raw shell command in the release directory (cwd), 600s timeout, on the
cron worker (§SPEC 9). Include the interpreter yourself, and for PHP use an **absolute CLI path** — **not**
bare `php`. On cPanel bare `php` frequently resolves to the **php-cgi** (CGI SAPI) binary, which does not
populate `$argv`: artisan then prints its help list and **exits 0**, so the hook is recorded as *successful*
while the migration silently never runs. Use e.g. `/usr/local/bin/php artisan migrate --force` or
`/opt/cpanel/ea-php83/root/usr/bin/php artisan …`, `composer install --no-dev`, or `npm run build`. The
`/system/capabilities` endpoint reports which binaries the host detects — `binaries` (name → path/null) plus
`binaries_source` (`cron` once the worker has run `command -v` in the shell hooks use, else `path-scan` fallback)
and `binaries_detected_at`. The server trims commands and drops
blanks/empty stages, so an all-empty `hooks` persists as `{}`.

### Environment variables

Managed env vars are **admin-only** and handled via the dedicated `/projects/{slug}/env` endpoints — they
are **not** part of `POST /projects` / `PATCH /projects/{slug}` and never appear in `show`/`index` (values
are secret). Stored **encrypted at rest**; on deploy cPorter renders them into `shared/.env` (§SPEC 6 step
7b, §SPEC 9).

`GET` / `PUT` / `POST …/adopt` all return the same envelope:

```json
{ "data": { "vars": [{ "key": "APP_ENV", "value": "production" }], "file": { "exists": true, "managed": true } } }
```

- `vars` — ordered `{key,value}` list (decrypted). Keys must match `^[A-Za-z_][A-Za-z0-9_]*$`, be unique,
  ≤255 chars; values ≤32768 chars. `PUT` validates per-row (`422` on `env_vars.{i}.key` / `.value`); the
  server trims keys and drops blank-key rows.
- `file` — on-disk state of `shared/.env`: `exists` (present on the server) and `managed` (carries cPorter's
  marker header). `exists && !managed` means a hand-created file — deploys **skip** writing it (recorded as a
  non-fatal `warning` step) until taken over.
- `POST …/adopt` force-writes `shared/.env` from the current vars, stamping the marker so cPorter manages it
  going forward. It does **not** redeploy — the returned `message` reminds the operator to deploy to apply it
  to the live release.

**Deployment step:** when env vars are set, a `write_env` step precedes `link_shared` (`success` when
written, `warning` — with a `note` — when an unmanaged `shared/.env` is left untouched). See §8.

---

## 6. Idempotency

Send an `Idempotency-Key` header on `POST …/deployments`. If a deployment already exists for
`(project_id, idempotency_key)`, the server **replays** the existing deployment instead of creating a
duplicate (unique DB index enforces this). Safe for CI retries. The GitHub Action defaults this to the
commit SHA.

## 7. Concurrency

Deploys are **serialized per project as a FIFO backlog** (SPEC §22). A project runs at most one deploy at a
time; a deploy requested while another is in progress for the same project is **accepted and queued**
(returned with `status: queued`) and starts automatically, oldest-first, once the project is free — it is
**not** rejected. Different projects are independent and can run concurrently. Poll the returned deployment
for progress (`queued → running → …`).

The per-project filesystem lock (`deploy.lock`, `O_EXCL` + TTL steal) remains the in-pipeline mutex. Manual
`POST …/rollback` and release-activate return `409 Conflict` only while a deploy is **actively running**
(`running`/`hooks_pending`) for that project — a purely-queued backlog does not block a rollback.

## 8. The `Deployment` object

Shape returned by deployment endpoints (see `packages/sdk/src/types.ts` for the canonical TS types):

```jsonc
{
  "id": 123,
  "status": "running",              // see statuses below
  "trigger": "api",                 // api | manual | cron | webhook
  "actor": "ci-deploy",
  "release": { "id": 45, "version": "abc123", "state": "active" },
  "steps": [                        // the log/timeline lives here — no separate /logs endpoint
    { "name": "extract", "status": "success", "duration": 0.4, "output": "…" }
  ],
  "idempotency_key": "…",
  "started_at": "2026-07-19T…",
  "finished_at": "2026-07-19T…"
}
```

**Deployment statuses:** `queued` → `running` → (`hooks_pending` for Laravel) → terminal one of
`success` | `failed` | `rolled_back`.
**Terminal statuses:** `success`, `failed`, `rolled_back` (clients poll until one of these).

**Step shape:** each `steps[]` entry is `{ name, status, duration_ms, error?, note? }`. `status` is
`success` | `failed` | `warning`. A `warning` is non-fatal (the deploy continues) and carries a `note`
— e.g. `write_env` skipping a hand-created, unmanaged `shared/.env`. A successful step may also carry a
`note` — e.g. the post-deploy `prune_artifacts` step reports how many artifact archives it reclaimed.

**Artifact reclaim:** after a successful deploy, redundant artifact `.zip`s are deleted from disk (only the
live release's is kept). The `Artifact` DB row persists for reporting — its `storage_path` becomes `null` and
`pruned_at` is set once the file is gone (a release's `artifact.size`/`sha256` stay available). See SPEC §6 step 15b.

---

## 9. Keeping this doc honest

When you change a route, request/response shape, scope, or status enum:
1. Update `apps/api/routes/api.php` / the controller **and this file** in the same change.
2. If the wire shape changed, update `packages/sdk/src/types.ts` and `client.ts` (the SDK is the reference client).
3. The ⚠️ notes above mark **known contract gaps**; when a gap is closed in code, remove the note here and
   from SPEC §20.
