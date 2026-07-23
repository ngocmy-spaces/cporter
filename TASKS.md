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
| **T0.4** | CI self-deploy workflow | ✅ | `.github/workflows/deploy.yml`: build web → composer install --no-dev → `pnpm build:artifact` → deploy via the **cPorter GitHub Action** (`packages/github-action@v1` → CLI), `project: cporter` inline, secrets `CPORTER_HOST`/`CPORTER_TOKEN`. `build-artifact.mjs` emits artifact/sha256/version via `$GITHUB_OUTPUT`. *(Originally a direct POST with `CPORTER_URL`; migrated to the Action once T4.1/T4.3 shipped.)* | §14 |
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
| **T1.10** | Tests pipeline | ✅ | e2e feature tests (real temp-dir adapter, mạnh hơn fake): deploy static + rollback + **lock-conflict** + hash-mismatch + scope. Bao phủ trong DeployPipelineTest/RollbackTest/adapter tests | §15 |

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
| **T3.1** | Dashboard widgets | ✅ | Alerts card (failed/in-flight/stuck >10' + "All clear") + rows click → DeploymentDrawer, cạnh 4 stat cards | §13 |
| **T3.2** | Users & roles | ✅ | `users.role` (admin/viewer) + `role:admin` gate writes; UserController CRUD; UsersPage (create/delete, no self-delete); UI ẩn nút write + menu Users cho viewer | §13 |
| **T3.3** | API Keys/Tokens UI | ✅ | Đã có từ T1.9 (create token-once, scopes MultiSelect, project, last_used, revoke) | §12, §13 |
| **T3.4** | Audit log UI | ✅ | `AuditLogger` ghi 9 action + `GET /audit-logs`; LogsPage = bảng audit (action/actor/subject/meta/ip/when) + filter action, poll 15s | §12 |
| **T3.5** | Housekeeping | ✅ | Đã có từ T2.5 (`cporter:housekeep`: fail timeout + nhả lock treo, theo lịch) | §10 |
| **T3.6** | Webhooks | ✅ | `POST /webhooks/{github\|gitlab}` verify HMAC/token + ghi audit (env `CPORTER_WEBHOOK_SECRET`); cấu hình qua env, không cần UI | §7, §12 |
| **T3.7** | Env-var management | ✅ | `Project.env_vars` **mã hoá at-rest** (Crypt); admin-only `GET/PUT /projects/{slug}/env` + `POST …/env/adopt`; deploy render `shared/.env` (step `write_env` trước `link_shared`) với marker ownership — file người dùng tự tạo không bị ghi đè (step `warning`, không fail deploy); Environment tab (editor value masked, import từ .env, take-over action). *(Không có trong thiết kế gốc; thêm trong thực tế.)* | §5, §6, §9, §20.1, §20.5 |

---

## Phase 4 — Ecosystem

| ID | Task | Status | Deliverable | Spec |
|----|------|:----:|---|---|
| **T4.1** | GitHub Action | ✅ | `packages/github-action` composite action → CLI; floating tag `@v1`; referenced by monorepo subpath. *(Marketplace listing not done — requires root `action.yml`; see RELEASING.md.)* | §18 |
| **T4.2** | JS SDK | ✅ | `@cporter/sdk` — TS client core (deploy/chunked/rollback/whoami/wait); published to npm. **PHP SDK: ⬜ not started.** | §18 |
| **T4.3** | CLI | ✅ | `@cporter/cli` — `deploy`/`status`/`rollback`/`whoami`, env + flags, exit-code contract; published to npm | §18 |
| **T4.4** | MCP server | ✅ | `@cporter/mcp` — 4 tools (`cporter_deploy/status/rollback/whoami`) over stdio, wraps the SDK. *(Not in the original design; added in practice.)* README + [SPEC §18]. **⚠️ not yet in `publish.yml`** — see T5.6 | §18 |
| **T4.5** | Self-deploy | ✅ | `deploy.yml` self-deploys `project: cporter` on push to `main` via the Action | §14 |
| **T4.6** | Plugin/Adapter API · multi-account | ⬜ | SshStorageAdapter, multi-server/multi-account | §14, §18 |

---

## Phase 5 — Hardening & known gaps (backlog)

> Sourced from the 2026-07-19 spec↔impl reconciliation ([SPEC §20](docs/SPEC.md#20-as-built-deltas--known-gaps)).
> These are real behavioural/contract gaps deferred by decision ("docs first, code later"). Ordered by priority.

| ID | Task | Priority | Deliverable / Notes | Spec |
|----|------|:----:|---|---|
| **T5.1** | ~~Rollback: run hooks + health-check~~ | ✅ Resolved by design | **Superseded by Phase 6 (§21).** Decision 2026-07-22: manual rollback stays code-only (§21.3); post-rollback health moves to continuous monitoring (T6.1); auto-rollback becomes opt-in single-target-or-stay (T6.2). | §8, §21 |
| **T5.2** | Concurrent deploy → `409` | ✅→♻️ | Shipped as request-time `409`, then **superseded by Phase 7** (per-project FIFO backlog): deploys now **queue** instead of reject. The `guardNoConcurrentDeploy` mechanism lives on, narrowed to guard **rollback/activate** against an actively-running deploy only. | §6, §20.2, §22 |
| **T5.3** | Strengthen pipeline "Validate" (step 9) | ✅ | `DeployEngine::validateStructure` now verifies the type's entrypoint exists in the docroot (Laravel/PHP/WordPress → `index.php`; static → `index.html`/`.htm`; Node exempt), not just that the docroot is a directory. Mispackaged artifacts fail before going live. Test in DeployPipelineTest. | §6, §20.2 |
| **T5.4** | CI read-scope for projects/releases | ✅ | **Decision: expose only `/projects/{slug}/releases`** (dual-auth via new `read.session_or_apikey` middleware — read-scope API key or admin session; project-scoped keys limited to their own project). `GET /projects` (list) stays admin-session-only. `ReleaseController::index` gains the project-scope guard; tests in DeployPipelineTest. Docs: API.md §4, SPEC §7/§20.1. | §7, §20.1 |
| **T5.5** | Deployment logs endpoint | ✅ | **Dropped from the contract by decision** — logs live in `steps[]` (returned by `GET …/deployments/{id}`). Removed the `/logs` row from SPEC §7; §20.1 marked resolved; API.md already had no such endpoint. | §7, §20.1 |
| **T5.6** | Publish `@cporter/mcp` | ✅ | **Decision: keep repo-only** — not published to npm. Run from a local build/source; `publish.yml` ships sdk + cli only. RELEASING.md + SPEC §20.6 record the decision and how to revisit it. | §18, §20.6 |

**Milestone M5:** ✅ ACHIEVED (2026-07-23) — all hardening/known-gap items closed: T5.1 resolved by design (Phase 6), T5.2/T5.3 shipped with tests, T5.4/T5.5/T5.6 resolved by decision. §20 backlog cleared.

---

## Phase 6 — Release control, health monitoring & rollback policy (v1.1)

> Decided 2026-07-22 ([SPEC §21](docs/SPEC.md#21-v11--release-control-health-monitoring--rollback-policy-planned)).
> Ordered by ascending risk — each slice ships independently. Legend: ⬜ todo.

| ID | Task | Status | Deliverable / Notes | Spec |
|----|------|:----:|---|---|
| **T6.1** | Continuous project health | ✅ | Migration `projects.health_status`(`healthy\|unhealthy\|unknown\|paused`) + `health_checked_at` + `health_last_ok_at` + `ProjectHealth` enum. `ProjectHealthMonitor` = single writer; `cporter:check-health` command (scheduled every 5' + in `cporter:work` loop) polls non-deleting projects (single-shot, `monitor_timeout=0`). Deploy-time gate writes through `health_status`. Dashboard alert + Projects-list **Health** column + detail Overview read `project.health_status` directly. | §21.1 |
| **T6.2** | Auto-rollback = per-trigger opt-in policy | ✅ | Migration `projects.auto_rollback_on` (JSON list of `RollbackTrigger`: `health_check`, `post_activate_hook`; default empty) + create/edit **Checkbox.Group**. `DeployEngine::handleActivationFailure($trigger)` gated by `Project::shouldAutoRollback()`: select a **single** previous release, verify `is_dir`; valid → `rolled_back` (project→`unknown`), none/not-opted-in → stay on new release + `failed` + `unhealthy` (no loop/crash). Only the two modelled triggers roll back — `prune`/`disk_stats` demoted to non-fatal warnings. Enum-driven → extensible. | §21.2 |
| **T6.3** | `keep_releases` edit no longer blocks | ✅ | Removed `assertKeepReleasesAllowed` + its call + comment in `ProjectController::update`; kept `pruneReleases` (protects the active release). `KeepReleasesRetentionTest` block-test flipped → asserts the edit succeeds and the live release survives outside the new window. | §21.4 |
| **T6.4** | Releases tab UX | ✅ | `active` → static **Live** badge (no self-targeting button); `superseded` → **Rollback** action + code-only confirm copy. Intro clarifies "code version applied" vs "available to roll back to". | §21.5 |

**Milestone M6:** ✅ ACHIEVED — rollback is an explicit, opt-in policy; project health is a persisted, continuously-monitored signal read from one source; retention never blocks the operator.

---

## Phase 7 — Deploy concurrency: per-project FIFO backlog + multi-worker (v1.2)

> Decided 2026-07-23 ([SPEC §22](docs/SPEC.md#22-deploy-concurrency--per-project-fifo-backlog--multi-worker-planned)).
> Replaces T5.2's `409`-reject: a concurrent deploy now **queues** and runs after, in order; different projects run independently.

| ID | Task | Status | Deliverable / Notes | Spec |
|----|------|:----:|---|---|
| **T7.1** | Per-project FIFO backlog | ✅ | `App\Domain\Deploy\DeployDispatcher`: `claimNextFor(Project)` (per-project `Cache::lock` + oldest-`queued` conditional-UPDATE CAS → dispatch) and `dispatchPending()` (drain idle projects). Deploy request drops the `409`, creates `Deployment{queued}`, and "kicks" the dispatcher (idle → runs now / inline under sync; busy → parked). `Project::activeDeployment()` (running/hooks_pending) is the precheck. Backlog drained at the tail of `cporter:run-jobs` + `cporter:housekeep` (self-heal); thin `cporter:dispatch-deploys` for tests/ops. Tests in DeployBacklogTest. | §22.1 |
| **T7.2** | Multi-worker-safe execution | ✅ | Claim is atomic across requests + workers (per-project cache lock + affected-rows CAS); `DeployEngine::deploy()` early-returns on a terminal row (double-delivery guard); `config/queue.php` `retry_after` 90→660 (> job timeout 600) so the DB queue can't re-reserve a long deploy. cPanel stays single-worker (cooperative); Docker/VPS run N `queue:work` for true parallelism — documented in DEPLOYMENT-CPANEL.md §4. | §22.2 |
| **T7.3** | Backlog recovery + rollback interaction | ✅ | Housekeep reaps stuck in-flight → releases lock → `dispatchPending()` (backlog never stranded); no timer-based `queued` reaper. Manual rollback/activate `409` narrowed to an **actively running** deploy — a queued backlog doesn't block rollback. Tests in RollbackTest + DeployBacklogTest. | §22.3, §22.4 |

**Milestone M7:** ✅ ACHIEVED (2026-07-23) — deploys queue per-project (FIFO) instead of being rejected; the claim is safe under N workers; cPanel default unchanged, Docker/VPS can deploy projects in parallel.

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
