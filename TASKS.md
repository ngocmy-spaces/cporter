# cPorter — Task Breakdown

Broken down from [docs/SPEC.md](docs/SPEC.md) by phase. Each task states its **deliverable**, **dependencies**,
and **spec reference**. Legend: ✅ done · 🔜 next · ⬜ todo · 🔒 blocked.

> Decisions locked in: single-tenant · MySQL · artifact = ZIP · **no exec in web PHP → cron-worker** ·
> MVP supports static/React SPA + Laravel + WordPress/PHP. Details: SPEC §17.1.

---

## Phase 0 — Foundation

| ID | Task | Status | Deliverable / Notes | Spec |
|----|------|:----:|---|---|
| **T0.1** | Monorepo scaffold | ✅ | `apps/{api,web}`, pnpm workspace, `.gitignore/.editorconfig`, `build/build-artifact.mjs`, README | §4.2 |
| **T0.2** | Web SPA shell | ✅ | React 19 + Vite + TS + **Mantine v9** + Router + React Query; AppShell layout + 8 stub pages; **build + lint pass**. FE docs/skill/agent: [docs/FRONTEND.md](docs/FRONTEND.md) | §13 |
| **T0.3** | API Laravel 12 skeleton | ✅ | Laravel 12/PHP 8.2, Sanctum, `/api/v1/health`, `Adapters/{Storage,Command}` interfaces, `cporter:run-jobs` stub, `config/cporter.php`; **tests + migrate pass** | §15 |
| **T0.4** | CI self-deploy workflow | ✅ | `.github/workflows/deploy.yml`: build web → composer install --no-dev → `pnpm build:artifact` → POST Deploy API (secrets CPORTER_URL/TOKEN/PROJECT, idempotency=SHA). `build-artifact.mjs` emits artifact/sha256/version via `$GITHUB_OUTPUT` | §14 |
| **T0.5** | Domain model + migrations | ✅ | 6 migrations + Eloquent models (`projects`/`releases`/`artifacts`/`deployments`/`api_keys`/`audit_logs`) + 6 backed enums in `app/Enums`; ProjectFactory. **migrate + 5 tests pass**. Models in `app/Models`, `app/Domain` reserved for the engine | §5 |
| **T0.6** | Auth + API keys + capability probe | ✅ | Admin auth = same-origin session (web guard): `/auth/login\|logout\|user`. API key `cpk_…` (prefix + sha256 hash) via `ApiKeyService` + middleware `apikey`/`scope:` + scopes(`read/deploy/rollback/admin`, admin=super) + `/whoami` + admin CRUD `/api-keys`. `GET /system/capabilities` (probe ext/functions/symlink/disk/limits). **16 tests pass** | §9.3, §12 |
| **T0.7** | Settings + jail config | ✅ | `PathJail` (normalize + realpath symlink-resolve, guards against traversal/symlink-escape/prefix-confusion, deny-all by default) bound as singleton; `Setting` model + table (stores probe); `/system/capabilities` persist + `/refresh`; boot-validate base paths. **27 tests pass** | §11, §12 |

---

## Phase 1 — MVP Deploy (static / WordPress / plain PHP — NO shell required)

> Goal: fully deploy **synchronously within web PHP** for apps that need no hooks. This is the lowest-risk slice.

| ID | Task | Status | Deliverable / Notes | Spec |
|----|------|:----:|---|---|
| **T1.1** | Path jail + Zip-Slip util | ✅ | `PathJail` (T0.7) + Zip-Slip guard (per-entry via inner PathJail in `extractZip`) | §11, §12 |
| **T1.2** | FS Adapter: artifact + extract | ✅ | `putArtifact` (move→storage, size cap, sanitize slug) + `extractZip` (ZipArchive, Zip-Slip + file/uncompressed caps) in `CpanelFilesystemAdapter`; bind `StorageAdapter`. **36 tests pass** | §6, §11 |
| **T1.3** | FS Adapter: release + swap + lock | ✅ | `activate` (atomic symlink swap + copy-swap fallback), `linkShared` (seed shared + symlink, persisted per release), `pruneReleases` (does not delete active release), `acquireLock/releaseLock` (O_EXCL + TTL steal), `currentTarget`. **41 tests pass** — `CpanelFilesystemAdapter` complete | §6, §8, §11 |
| **T1.4** | Deploy API: create + upload (single) | ✅ | `POST /projects/{slug}/deployments` (apikey+scope:deploy, upload ≤256MB, verify sha256, idempotency, `202`) + `GET …/deployments/{id}` (poll) + ProjectController (admin: create in jail/list/show) | §6, §7 |
| **T1.5** | Deploy Engine (static/wp/php pipeline) | ✅ | `DeployEngine` + `DeployProjectJob` (queue; sync dev/test, async cron prod): lock→extract→link_shared→validate→activate→prune→success, recording `Deployment.steps[]` at each step. **49 tests pass** | §6 |
| **T1.6** | Health check + auto-rollback | ✅ | `HealthChecker` (Http/cURL, retry until timeout) after activate; on fail → `auto_rollback` to previous release, deployment=`rolled_back`. Shares `StepRunner` | §6, §8 |
| **T1.7** | Rollback engine + endpoint | ✅ | `RollbackEngine` (previous/specified release, code-only) + `POST /projects/{slug}/rollback` (apikey+scope:rollback). **54 tests pass** | §8 |
| **T1.8** | Read endpoints | ✅ | Admin (session): `GET /deployments` (recent), `GET /deployments/{id}`, `GET /projects/{p}/deployments`, `GET /projects/{p}/releases`, `POST /releases/{id}/activate` (rollback từ UI). Logs = `Deployment.steps`. **58 tests pass** | §7 |
| **T1.9** | Admin UI (deploy core) | ✅ | Mantine SPA nối API thật: login (session+CSRF via `/csrf`), Dashboard, Projects (+create), Deployments (+drawer steps Timeline poll), Releases (+activate/rollback), Settings (capabilities), API Keys (+token-once). Build+lint xanh. Backend hardening: JSON 401 cho `/api/*` (no `login` redirect) + `/csrf`. **60 tests** | §13 |
| **T1.10** | Tests pipeline | 🔒 T1.5 | `FakeStorageAdapter`; e2e feature test deploy static + rollback + lock-conflict | §15 |

**Milestone M1:** ✅ ACHIEVED — deploy + rollback static site via API, atomic, health check + auto-rollback.

---

## Phase 2 — Laravel target + hooks + chunked upload

| ID | Task | Status | Deliverable / Notes | Spec |
|----|------|:----:|---|---|
| **T2.1** | Job queue + cron-worker | ✅ | `cporter:run-jobs` finalize các deployment `hooks_pending` trong **cron shell context** (deploy giữ lock, cron nhả); steps ghi output/exit từng hook | §9.1, §10 |
| **T2.2** | CommandRunner | ✅ | `CommandRunner` interface + `ProcessCommandRunner` (Symfony Process/proc_open, `isAvailable()`); shell-unavailable → step `run manually`. Verified chạy shell thật | §9.2 |
| **T2.3** | Hooks in pipeline (Laravel) | ✅ | DeployEngine: project có hooks → stage → `hooks_pending`; finalize = pre-hooks→activate→post-hooks→health→prune, auto-rollback nếu fail sau activate. **64 tests pass** | §6, §9 |
| **T2.4** | Chunked upload + idempotency | ✅ | `ArtifactUploadService` + `POST artifacts/uploads` / `PUT …/chunks/{n}` (raw body) / `POST …/complete` (assemble→verify→deploy); `Idempotency-Key` replay chia sẻ. **67 tests** | §6 |
| **T2.5** | Scheduler tick + cron setup | ✅ | Schedule (1 cPanel cron → `schedule:run`): `cporter:run-jobs` + `queue:work` mỗi phút, `cporter:housekeep` mỗi 5' (fail deployment timeout + nhả lock treo). Cron line trong README. **65 tests** | §10 |
| **T2.6** | Admin: hooks & capability | ✅ | UI surface `hooks_pending` (badge + drawer poll tới terminal, hook steps + lỗi) + capabilities ở Settings (T1.9). Manual-retry hoãn (cron auto-finalize + housekeep) | §13 |

**Milestone M2:** ✅ ĐẠT — deploy Laravel end-to-end (hooks migrate/cache/queue) qua cron-worker + scheduler + chunked upload. Phase 2 hoàn tất.

---

## Phase 3 — Admin polish & Scheduler

| ID | Task | Status | Deliverable | Spec |
|----|------|:----:|---|---|
| **T3.1** | Dashboard widgets | ⬜ | Recent deploys, success rate, alerts (stale lock, migration pending) | §13 |
| **T3.2** | Users & roles | ⬜ | Admin user CRUD + roles | §13 |
| **T3.3** | API Keys/Tokens UI | ⬜ | Create/revoke token, assign scope + project, last_used | §12, §13 |
| **T3.4** | Audit log UI | ⬜ | Filter by project/status/time | §12 |
| **T3.5** | Housekeeping | ⬜ | Prune releases, timeout cleanup on a schedule | §10 |
| **T3.6** | Webhooks | ⬜ | `POST /webhooks/{provider}` verify HMAC (GitHub/GitLab) | §7, §12 |

---

## Phase 4 — Ecosystem (future)

| ID | Task | Deliverable | Spec |
|----|------|---|---|
| **T4.1** | Official GitHub Action | Action published to the marketplace | §18 |
| **T4.2** | JS SDK + PHP SDK | Client libraries calling the Deploy API | §18 |
| **T4.3** | CLI | `cporter deploy …` | §18 |
| **T4.4** | Plugin/Adapter API · multi-account · self-deploy | SshStorageAdapter, multi-server, cPorter self-update | §14, §18 |

---

## Suggested starting order

```
T0.5 (domain model)  →  T0.6 (auth/api key)  →  T0.7 (settings/jail)
        └────────────────────┴─────────────→ T1.1 → T1.2 → T1.3
                                                          └→ T1.4 → T1.5 → T1.6/T1.7 → T1.8 → T1.9 → T1.10  ⇒ M1
```

**Task to start immediately:** **T0.5 — Domain model + migrations** (unlocks nearly all of Phase 1), and
in parallel **T0.6 — Auth + API key + capability probe**.

---

## Open questions (do not block T0.5/T0.6) — see SPEC §17.2
Real-world artifact size · WordPress scope · per-type health-check URL · cron interval · first CI run.
