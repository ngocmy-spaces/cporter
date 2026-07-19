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

### `POST /projects/{slug}/rollback`  — scope: `rollback`
Roll the project back. Body: `{ "release_id": <int> }` (optional — omit to roll back to the previous release; the SDK/MCP option `releaseId` maps to this snake_case wire field).
Returns `{ "data": <Deployment> }`.
> Current behavior: **symlink swap only** (no post-activate hooks / no health-check re-run yet — tracked in the backlog, SPEC §20).

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
| GET/POST/DELETE | `/api-keys` · `/api-keys/{id}` | read / admin | token CRUD (plaintext shown once) |
| GET | `/projects` · `/projects/{slug}` | read | list / show projects |
| POST | `/projects` | admin | create a project (jail-validated `base_path`) |
| PATCH | `/projects/{slug}` | admin | update project config; `status: disabled` blocks new deploys. `slug`/`base_path`/`type` are frozen once releases exist |
| GET | `/projects/{slug}/deployments` · `/projects/{slug}/releases` | read | per-project history |
| GET | `/deployments` · `/deployments/{id}` | read | recent + detail |
| POST | `/releases/{id}/activate` | admin | activate a release (rollback-from-UI) |
| GET | `/audit-logs` | read | audit trail |
| GET/POST/DELETE | `/users` · `/users/{id}` | admin | admin-user management |

> ⚠️ **Contract gap to know:** SPEC §7 listed `GET /projects` and `GET /projects/{slug}/releases` as CI
> *read-scope* endpoints, but they are implemented **admin-session-only**. A read-scope API key cannot list
> projects or releases today. If CI needs this, it's a backlog item (SPEC §20).

---

## 6. Idempotency

Send an `Idempotency-Key` header on `POST …/deployments`. If a deployment already exists for
`(project_id, idempotency_key)`, the server **replays** the existing deployment instead of creating a
duplicate (unique DB index enforces this). Safe for CI retries. The GitHub Action defaults this to the
commit SHA.

## 7. Concurrency

Deploys are serialized per project by a filesystem lock (`deploy.lock`, `O_EXCL` + TTL steal).
> ⚠️ The lock is acquired **inside** the async deploy job, *after* upload. A second concurrent deploy
> therefore currently **fails asynchronously** (recorded as a failed deployment) rather than returning a
> synchronous `409 Conflict` as SPEC §6 envisioned. Treat a lock-conflict as a failed deployment. (Backlog: SPEC §20.)

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

---

## 9. Keeping this doc honest

When you change a route, request/response shape, scope, or status enum:
1. Update `apps/api/routes/api.php` / the controller **and this file** in the same change.
2. If the wire shape changed, update `packages/sdk/src/types.ts` and `client.ts` (the SDK is the reference client).
3. The ⚠️ notes above mark **known contract gaps**; when a gap is closed in code, remove the note here and
   from SPEC §20.
