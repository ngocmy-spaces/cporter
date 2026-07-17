# cPorter — Task Breakdown

Chia nhỏ từ [docs/SPEC.md](docs/SPEC.md) theo phase. Mỗi task ghi rõ **deliverable**, **phụ thuộc**,
và **tham chiếu spec**. Ký hiệu: ✅ done · 🔜 next · ⬜ chưa · 🔒 chờ phụ thuộc.

> Quyết định đã chốt: single-tenant · MySQL · artifact = ZIP · **không exec ở web PHP → cron-worker** ·
> MVP hỗ trợ static/React SPA + Laravel + WordPress/PHP. Chi tiết: SPEC §17.1.

---

## Phase 0 — Foundation

| ID | Task | Trạng thái | Deliverable / Ghi chú | Spec |
|----|------|:----:|---|---|
| **T0.1** | Monorepo scaffold | ✅ | `apps/{api,web}`, pnpm workspace, `.gitignore/.editorconfig`, `build/build-artifact.mjs`, README | §4.2 |
| **T0.2** | Web SPA shell | ✅ | React 19 + Vite + TS + **Mantine v9** + Router + React Query; AppShell layout + 8 trang stub; **build + lint pass**. FE docs/skill/agent: [docs/FRONTEND.md](docs/FRONTEND.md) | §13 |
| **T0.3** | API Laravel 12 skeleton | ✅ | Laravel 12/PHP 8.2, Sanctum, `/api/v1/health`, `Adapters/{Storage,Command}` interfaces, `cporter:run-jobs` stub, `config/cporter.php`; **tests + migrate pass** | §15 |
| **T0.4** | CI self-deploy workflow | 🔜 | `.github/workflows/deploy.yml`: build web → composer install --no-dev → `pnpm build:artifact` → gọi Deploy API. Hoàn thiện khi có T1.4 | §14 |
| **T0.5** | Domain model + migrations | ✅ | 6 migrations + Eloquent models (`projects`/`releases`/`artifacts`/`deployments`/`api_keys`/`audit_logs`) + 6 backed enums trong `app/Enums`; ProjectFactory. **migrate + 5 tests pass**. Models ở `app/Models`, `app/Domain` để dành cho engine | §5 |
| **T0.6** | Auth + API keys + capability probe | 🔜 | Admin login (Sanctum SPA cookie); API key hashed + scopes (`read/deploy/rollback/admin`) + per-project; endpoint `GET /api/v1/system/capabilities` (probe symlink/zip/proc_open/disk/php) | §9.3, §12 |
| **T0.7** | Settings + jail config | ⬜ | Model Settings (lưu kết quả probe); validate `CPORTER_ALLOWED_BASE_PATHS` khi boot; helper `PathJail` | §11, §12 |

---

## Phase 1 — MVP Deploy (static / WordPress / PHP thuần — KHÔNG cần shell)

> Mục tiêu: deploy trọn vẹn **đồng bộ trong web PHP** cho app không cần hook. Đây là lát cắt rủi ro thấp nhất.

| ID | Task | Trạng thái | Deliverable / Ghi chú | Spec |
|----|------|:----:|---|---|
| **T1.1** | Path jail + Zip-Slip util | 🔒 T0.7 | `PathJail::assertInside()`, chuẩn hóa realpath, chặn traversal/symlink-escape; unit test độc hại | §11, §12 |
| **T1.2** | FS Adapter: artifact + extract | 🔒 T1.1 | `putArtifact`, `extractZip` (ZipArchive, chống zip-slip, cap inode/size) | §6, §11 |
| **T1.3** | FS Adapter: release + swap + lock | 🔒 T1.1 | `activate` (symlink swap + **fallback rename-swap**), `linkShared`, `pruneReleases`, `acquireLock/releaseLock` (O_EXCL + TTL) | §6, §8, §11 |
| **T1.4** | Deploy API: create + upload (single) | 🔒 T0.5,T0.6 | `POST /projects/{slug}/deployments` (+ single upload ≤256MB), verify sha256, `202 Accepted` + poll URL | §6, §7 |
| **T1.5** | Deploy Engine (pipeline static/wp/php) | 🔒 T1.2,T1.3,T1.4 | Orchestrate step 1–16 cho `type` không hook; ghi `Deployment.steps[]` + `deploy.log` | §6 |
| **T1.6** | Health check + auto-rollback | 🔒 T1.5 | cURL health check; fail sau activate → tự đổi symlink về release cũ | §6, §8 |
| **T1.7** | Rollback engine + endpoint | 🔒 T1.3 | `POST /projects/{slug}/rollback` (release trước / chỉ định), code-only | §8 |
| **T1.8** | Read endpoints | 🔒 T0.5 | `GET deployments/{id}`, `/logs`, `/releases`, `/projects` | §7 |
| **T1.9** | Admin UI (deploy core) | 🔒 T1.4,T1.8 | Projects CRUD; Deployments list + detail (poll steps/log); Releases (activate=rollback); Logs | §13 |
| **T1.10** | Tests pipeline | 🔒 T1.5 | `FakeStorageAdapter`; feature test e2e deploy static + rollback + lock-conflict | §15 |

**Milestone M1:** deploy + rollback 1 site static/WordPress qua API từ CI. 🎉

---

## Phase 2 — Laravel target + hooks + chunked upload

| ID | Task | Trạng thái | Deliverable / Ghi chú | Spec |
|----|------|:----:|---|---|
| **T2.1** | Job queue + cron-worker | 🔒 M1 | Bảng `jobs` (shell jobs), lệnh `cporter:run-jobs` thực thi trong **cron shell context**, ghi exit/output | §9.1, §10 |
| **T2.2** | CommandRunner drivers | 🔒 T2.1 | `CronWorkerRunner` (chính), `ManualRunner` (fallback → `hooks_pending`); chọn theo `command_driver` | §9.2 |
| **T2.3** | Hooks trong pipeline (Laravel) | 🔒 T2.2 | pre/post-activate enqueue (`migrate --force`, `config:cache`, `queue:restart`); status `hooks_pending` → success sau cron | §6, §9 |
| **T2.4** | Chunked upload + idempotency | 🔒 T1.4 | `POST artifacts` / `PUT chunks/{n}` / `POST complete`; header `Idempotency-Key` | §6 |
| **T2.5** | Scheduler tick + cron setup | 🔒 T2.1 | `schedule:run` mỗi phút; doc/script tạo cron cPanel (+ UAPI nếu có); dọn lock treo, timeout deployment | §10 |
| **T2.6** | Admin: hooks & capability | 🔒 T2.3,T0.6 | Hiển thị migration-pending, capability probe (Settings), retry hook thủ công | §13 |

**Milestone M2:** deploy Laravel end-to-end (kèm migrate) qua cron-worker.

---

## Phase 3 — Admin polish & Scheduler

| ID | Task | Trạng thái | Deliverable | Spec |
|----|------|:----:|---|---|
| **T3.1** | Dashboard widgets | ⬜ | Deploy gần đây, success rate, cảnh báo (lock treo, migration pending) | §13 |
| **T3.2** | Users & roles | ⬜ | CRUD user admin + role | §13 |
| **T3.3** | API Keys/Tokens UI | ⬜ | Tạo/thu hồi token, gán scope + project, last_used | §12, §13 |
| **T3.4** | Audit log UI | ⬜ | Filter theo project/status/time | §12 |
| **T3.5** | Housekeeping | ⬜ | Prune release, timeout cleanup theo lịch | §10 |
| **T3.6** | Webhooks | ⬜ | `POST /webhooks/{provider}` verify HMAC (GitHub/GitLab) | §7, §12 |

---

## Phase 4 — Ecosystem (tương lai)

| ID | Task | Deliverable | Spec |
|----|------|---|---|
| **T4.1** | GitHub Action chính thức | Action publish lên marketplace | §18 |
| **T4.2** | JS SDK + PHP SDK | Client thư viện gọi Deploy API | §18 |
| **T4.3** | CLI | `cporter deploy …` | §18 |
| **T4.4** | Plugin/Adapter API · multi-account · self-deploy | SshStorageAdapter, đa server, cPorter tự update | §14, §18 |

---

## Thứ tự bắt đầu đề xuất

```
T0.5 (domain model)  →  T0.6 (auth/api key)  →  T0.7 (settings/jail)
        └────────────────────┴─────────────→ T1.1 → T1.2 → T1.3
                                                          └→ T1.4 → T1.5 → T1.6/T1.7 → T1.8 → T1.9 → T1.10  ⇒ M1
```

**Task khởi động ngay:** **T0.5 — Domain model + migrations** (mở khóa gần như toàn bộ Phase 1) và
song song **T0.6 — Auth + API key + capability probe**.

---

## Câu hỏi còn treo (không chặn T0.5/T0.6) — xem SPEC §17.2
Kích thước artifact thực tế · phạm vi WordPress · health-check URL từng loại · cron interval · CI đầu tiên.
