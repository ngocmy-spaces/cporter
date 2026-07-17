# cPorter — Đặc tả kỹ thuật (Technical Specification)

> Trạng thái: **DRAFT v0.1** · Ngày: 2026-07-17 · Owner: dat.nguyen@siliconstack.com.au
>
> Tài liệu này mô tả kiến trúc, phạm vi và các quyết định kỹ thuật của cPorter.
> Các mục đánh dấu **[GIẢ ĐỊNH]** cần được xác nhận trước khi chốt (xem §17 Open Questions).

---

## 1. Tổng quan

**cPorter** là một công cụ orchestration deploy **self-hosted**, chạy như một web app bình thường
trên **cPanel shared hosting**, nhưng có khả năng **quản lý và deploy cho các thư mục domain khác**
nằm cùng tài khoản cPanel đó.

Mô hình vận hành:

- Người dùng đã tạo sẵn các addon domain / subdomain trên cPanel, mỗi domain trỏ document root vào
  một thư mục dưới `/home/<user>/` (ví dụ `learn.domain`, `shop.domain`, `api.domain`).
- cPorter tự cài vào một domain riêng (`deploy.domain`) và trở thành "control plane" đứng ra:
  nhận artifact từ CI, giải nén, quản lý release, đổi symlink `current` một cách atomic, health check,
  rollback — cho **tất cả** các project domain còn lại.
- CI (GitHub Actions / GitLab CI / Jenkins / bất kỳ) chỉ cần **build artifact và gọi HTTP API** của cPorter.

### 1.1 Mục tiêu (Goals)

1. Deploy **atomic, zero-downtime** trên cPanel mà **không cần root, không Docker, không systemd**.
2. Release có phiên bản, **rollback tức thì** (đổi symlink).
3. API-first: mọi CI đều tích hợp được qua HTTP + token.
4. Admin panel để quan sát & thao tác thủ công (dashboard, logs, rollback…).
5. Bản thân cPorter là **monorepo React (FE) + Laravel (BE)**, deploy chung 1 folder / 1 domain.

### 1.2 Ngoài phạm vi (Non-goals — bản đầu)

- Không tự cấp domain / DNS / SSL (người dùng làm trên cPanel).
- Không phải CI runner (không build code — build là việc của CI).
- Không quản lý nhiều tài khoản cPanel / nhiều server trong bản MVP (xem Roadmap).
- Không thay thế WHM/cPanel API cho quản trị hosting cấp cao.

---

## 2. Bối cảnh & Ràng buộc cPanel (QUAN TRỌNG NHẤT)

Đây là phần quyết định thiết kế. cPanel shared hosting khác hẳn VPS/root server.

| Ràng buộc | Ảnh hưởng thiết kế |
|---|---|
| PHP chạy **dưới quyền chính user cPanel** (suEXEC / PHP-FPM per-user) | ✅ cPorter (PHP) **đọc/ghi được mọi thư mục** dưới `/home/<user>/` → không cần root để thao tác sibling folders. Đây là nền tảng khiến ý tưởng khả thi. |
| `exec`/`proc_open` **KHÔNG dùng được** trên host này (đã test — §2.1); `system`/`shell_exec`/`passthru` bị chặn | ⚠️ **Không chạy được lệnh của app đích (vd `php artisan migrate`) từ web PHP.** Giải pháp chính: **cron-worker** chạy trong shell riêng của cron (không bị `disable_functions` chặn) — §9. Đây là rủi ro #1. |
| `symlink()` và `rename()` (PHP) thường **được bật** | ✅ Atomic swap `current` bằng symlink khả thi (Capistrano/Deployer style). |
| Không có `tar`/`unzip` qua shell (exec bị chặn) | ✅ Dùng **`ZipArchive` (ext-zip, đã test runtime)** để giải nén, không phụ thuộc shell. Artifact = `.zip` (§2.1). |
| **Inode limit** (giới hạn số file) rất chặt trên shared hosting | ✅ **Không** upload `node_modules`; FE build sẵn thành static ở CI. BE ship kèm `vendor/` đã cài. Giới hạn số release giữ lại (`keep_releases`). |
| **Disk quota** hạn chế | ✅ Prune release cũ; nén artifact; dọn tmp sau extract. |
| **max_execution_time / memory_limit / upload_max_filesize** thấp | ⚠️ Upload artifact lớn qua 1 request dễ timeout → hỗ trợ **chunked upload** + xử lý extract theo bước (§6). |
| Cron: cPanel cho tạo cron job (UI hoặc UAPI) | ✅ Một cron duy nhất gọi endpoint scheduler của cPorter (§10). |
| Document root add-on domain là **đường dẫn cố định** khi tạo domain | ✅ Trỏ docroot vào `.../current/public` (Laravel) hoặc `.../current` (static); Apache cPanel mặc định `SymLinksIfOwnerMatch` → symlink cùng owner hoạt động. |
| Không có Redis/systemd/queue worker thường trực | ✅ Queue dùng **driver `database`**; worker chạy bằng cron `queue:work --stop-when-empty` hoặc xử lý synchronous cho MVP. |
| PHP CLI binary của app đích có thể khác PHP-FPM (EA-PHP nhiều version) | ✅ Cấu hình **đường dẫn PHP binary per-project** (vd `/opt/cpanel/ea-php82/root/usr/bin/php`). |

> **[XÁC NHẬN]** Toàn bộ project được quản lý nằm **cùng một tài khoản cPanel** với cPorter
> (cùng `/home/<user>/`). Đây là single-tenant (chốt §17.6).

### 2.1 Hồ sơ năng lực môi trường (đã test thực tế — 2026-07-17)

> Nguồn: người dùng đã probe trực tiếp trên host đích. Đây là **facts**, chi phối toàn bộ thiết kế bên dưới.

- **Runtime:** PHP **8.3**, **LiteSpeed**, single cPanel account.
- **`open_basedir` = OFF** → PHP đọc/ghi được toàn bộ `/home/<user>/`. ✅ Cần thiết cho ý tưởng, nhưng
  **⚠️ jail đường dẫn phải tự enforce trong code** (bảo mật là trách nhiệm của cPorter, không có OS chặn hộ).
- **Đã test chạy OK (runtime):** `mkdir`, `rmdir`, `file_put_contents`, `rename`, `unlink`, `scandir`,
  `is_readable/is_writable/is_dir`, `disk_free_space/disk_total_space`, `ini_get`,
  và đầy đủ **`ZipArchive`** (`open`/`addFromString`/`close`/`extractTo`).
- **Function tồn tại nhưng CHƯA test runtime (coi là chưa chắc):** `copy`, `hash_hmac`, `random_bytes`,
  `readlink`, **`symlink`**, `exec`, `proc_open`, `popen`.
- **Extensions có:** zip, curl, json, openssl, phar, mbstring.
- **Bị chặn chắc chắn:** `system()`, `shell_exec()`, `passthru()`.
- **Quyết định từ hồ sơ này:**
  1. **`exec`/`proc_open` coi như KHÔNG dùng** → mọi lệnh cần shell (migrate/cache/queue) đi qua
     **cron-worker** (§9), không chạy trực tiếp trong web request.
  2. **Artifact = `.zip`** (dùng `ZipArchive` đã test chắc) — **không** dùng tar.gz/PharData.
  3. **`symlink()` phải probe lúc cài**; có **fallback rename-swap** nếu host cấm symlink (§8/§11).
  4. **Giới hạn upload:** `upload_max_filesize` 512MB, **`post_max_size` 256MB** → 1 request tối đa ~256MB;
     vượt thì **chunked upload** (§6).
  5. **HTTP client dùng cURL** (đã xác nhận) cho health check & webhook out.
  6. **LiteSpeed đọc `.htaccess`** (Apache-compatible) → rewrite của Laravel `public/` và SPA fallback chạy được.

---

## 3. Kiến trúc tổng thể

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

**Nguyên tắc**: mọi thao tác filesystem đều đi qua **Storage Abstraction → cPanel FS Adapter**, và
mọi lệnh cần shell đều đi qua **Command Runner** (có nhiều driver, fallback). Nhờ đó dễ test (fake adapter)
và dễ mở rộng sang môi trường khác (SSH adapter, S3 artifact store…).

---

## 4. Layout thư mục

### 4.1 Trên cPanel (runtime)

```
/home/user
├── learn.domain/            # 1 project được quản lý
│   ├── current -> releases/20260717_001   # symlink (atomic swap)
│   ├── releases/
│   │   ├── 20260717_001/                   # 1 release đã giải nén
│   │   ├── 20260718_001/
│   │   └── …
│   ├── shared/                             # tồn tại xuyên release
│   │   ├── .env
│   │   └── storage/                        # (Laravel) uploads, cache, logs
│   ├── deploy.lock                         # chống deploy trùng
│   └── deploy.log                          # log deploy của project này
│
├── shop.domain/  …
├── api.domain/   …
└── deploy.domain/           # <-- cPorter (cũng deploy theo cùng cơ chế)
    ├── current -> releases/…
    ├── releases/…
    ├── shared/{.env, storage/, database.sqlite?}
    └── storage/artifacts/                  # nơi lưu artifact upload tạm
```

- **`current`**: symlink tới release đang active. Docroot domain trỏ vào `current/public` (Laravel) hoặc `current` (static).
- **`releases/<id>`**: mỗi release là 1 thư mục bất biến. `<id>` = `YYYYMMDD_NNN` hoặc timestamp + short SHA.
- **`shared/`**: file/thư mục cần giữ nguyên qua các release (`.env`, `storage/`, uploads). Release symlink ngược vào đây.
- **`deploy.lock`**: file lock (chứa PID/deploy-id/timestamp) để serialize deploy per-project.
- **`deploy.log`**: log dạng dòng cho từng project (song song với log trong DB).

### 4.2 Monorepo source (repository cPorter)

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
├── build/                   # script gộp build → 1 artifact .zip deploy chung
│   └── build-artifact.mjs
├── .github/workflows/       # CI mẫu để self-deploy cPorter
├── package.json             # root: pnpm workspace, orchestrate build FE+BE
├── pnpm-workspace.yaml
└── README.md
```

**Chiến lược "1 folder, 1 domain"**: FE build ra static rồi đặt vào `apps/api/public/` của Laravel.
Laravel phục vụ `/api/*` là JSON API và fallback mọi route khác về `index.html` của SPA. Docroot
domain = `deploy.domain/current/public`.

---

## 5. Domain Model (dữ liệu)

DB: MySQL của cPanel (hoặc SQLite cho bản nhỏ — **[GIẢ ĐỊNH B]** dùng MySQL).

| Entity | Trường chính | Ghi chú |
|---|---|---|
| **User** | id, name, email, password, role | Admin panel login. |
| **ApiKey / Token** | id, name, prefix, hash, scopes[], project_id?, last_used_at, expires_at, revoked_at | Token cho CI. Lưu **hash** (Sanctum-style), hiển thị plaintext 1 lần. Scope: `deploy`, `read`, `rollback`, `admin`. |
| **Project** | id, name, slug, base_path, type(`laravel`\|`static`\|`php`\|`node`), docroot_subpath, php_binary, keep_releases, health_check_url, hooks(json), shared_paths(json), status | Cấu hình 1 domain được quản lý. |
| **Release** | id, project_id, ref/version, artifact_id, path, state(`pending`\|`extracting`\|`ready`\|`active`\|`superseded`\|`failed`), created_by, activated_at | 1 release vật lý. |
| **Artifact** | id, project_id, filename, size, sha256, storage_path, uploaded_at, status | File build từ CI. |
| **Deployment** | id, project_id, release_id, trigger(`api`\|`manual`\|`cron`), status(`queued`→`running`→`success`\|`failed`\|`rolled_back`), steps(json), started_at, finished_at, actor | 1 lần chạy pipeline. |
| **AuditLog** | id, actor, action, subject, meta(json), ip, created_at | Ai làm gì, khi nào. |

Quan hệ: `Project 1—* Release`, `Release 1—1 Artifact`, `Project 1—* Deployment`, `Deployment *—1 Release`.

---

## 6. Deploy Pipeline (chi tiết)

Mỗi bước ghi vào `Deployment.steps[]` (name, status, duration, output/tail) và stream ra `deploy.log`.

```
1. Receive Request      POST /api/v1/projects/{slug}/deployments  (metadata: version, sha256, size)
2. Authenticate         Token hợp lệ + scope `deploy` + đúng project
3. Acquire Lock         Tạo deploy.lock (atomic O_EXCL). Nếu đã lock → 409 Conflict (hoặc queue)
4. Upload Artifact      Nhận file .zip (single ≤256MB, hoặc chunked). Lưu vào storage/artifacts/<uuid>.zip
5. Verify Hash          Tính sha256 server-side == sha256 CI gửi. Sai → abort + unlock
6. Prepare Release Dir  Tạo releases/<id>/
7. Extract              ZipArchive::extractTo() vào release dir. Chống Zip-Slip. Kiểm inode/size cap
8. Link Shared          symlink .env, storage/… từ shared/ vào release. Tạo thư mục shared nếu thiếu
9. Validate             Kiểm cấu trúc bắt buộc (vd tồn tại public/index.php, index.html…)
10. Pre-activate Hooks  (Laravel) migrate, config:cache… → enqueue cron-worker (§9). static/WP/PHP: bỏ qua
11. Activate Release    symlink swap atomic: tạo current.tmp -> releases/<id> rồi rename → current
12. Post-activate Hooks (Laravel) queue:restart, opcache reset… → cron-worker. static/WP/PHP: bỏ qua
13. Health Check        GET health_check_url (cURL); kỳ vọng 2xx trong N giây. Fail → auto-rollback (§8)
14. Prune               Xóa release cũ vượt keep_releases
15. Success             Release.state=active, Deployment.status=success (Laravel: sau khi hooks cron xong)
16. Release Lock        Xóa deploy.lock (kể cả khi fail — finally)
```

**Chunked upload** (khi artifact > `post_max_size` 256MB — §2.1):
- `POST …/artifacts` → khởi tạo upload session (trả `upload_id`).
- `PUT …/artifacts/{upload_id}/chunks/{n}` → gửi từng phần.
- `POST …/artifacts/{upload_id}/complete` → ghép + verify sha256.

**Idempotency**: header `Idempotency-Key` để CI retry an toàn (không tạo deployment trùng).

**Xử lý lỗi**: bất kỳ bước 6–13 fail → dừng, KHÔNG đổi `current` (trừ khi đã ở bước 11+), ghi lỗi,
auto-rollback nếu đã activate, luôn `finally { unlock }`.

---

## 7. Deploy API (đề xuất endpoints)

Base: `https://deploy.domain/api/v1` · Auth: `Authorization: Bearer <token>`

| Method | Path | Mô tả |
|---|---|---|
| POST | `/projects/{slug}/deployments` | Tạo & chạy 1 deployment (kèm/không kèm artifact) |
| POST | `/projects/{slug}/artifacts` | Khởi tạo upload (chunked) |
| PUT | `/artifacts/{uploadId}/chunks/{n}` | Upload 1 chunk |
| POST | `/artifacts/{uploadId}/complete` | Hoàn tất + verify |
| GET | `/projects/{slug}/deployments/{id}` | Trạng thái + steps (poll) |
| GET | `/projects/{slug}/deployments/{id}/logs` | Stream/tail log |
| POST | `/projects/{slug}/rollback` | Rollback về release trước / release chỉ định |
| GET | `/projects/{slug}/releases` | Liệt kê releases |
| GET | `/projects` | Danh sách project (scope read) |
| POST | `/webhooks/{provider}` | Webhook (GitHub/GitLab) — verify HMAC signature |

**Response chuẩn**: JSON `{ data, meta, error }`. Deployment trả `202 Accepted` + `Location` để poll.

---

## 8. Rollback Engine

- **Nhanh (mặc định)**: đổi `current` về release trước đó (`superseded` gần nhất) hoặc release chỉ định →
  symlink swap atomic (tạo `current.tmp` rồi `rename`). Chạy post-activate hooks (cache clear). Health check.
- **Swap mechanism & fallback**: cơ chế chuẩn là **symlink swap** (`symlink()` + `rename()`). Nếu probe
  phát hiện host **cấm symlink**, fallback **rename-swap thư mục**: `rename(current → current.old)` rồi
  `rename(releases/<id> → current)` (có khoảng trống rất ngắn, chấp nhận cho host không hỗ trợ symlink;
  releases vẫn giữ bản sao để rollback).
- **Migration**: KHÔNG tự chạy `migrate:rollback` mặc định (nguy hiểm dữ liệu). Chỉ nêu cảnh báo &
  cho phép hook thủ công. → **[GIẢ ĐỊNH C]** rollback = code-only.
- **Auto-rollback**: nếu bước Health Check (13) fail sau khi activate → tự đổi symlink về release cũ,
  đánh dấu deployment `rolled_back`.

---

## 9. Command Execution Strategy (rủi ro #1 — đã chốt hướng đi)

**Vấn đề:** web PHP (LiteSpeed FPM) trên host này **không có `exec`/`proc_open`** (§2.1) → không thể
gọi `php artisan migrate` hay bất kỳ lệnh shell nào của app đích ngay trong HTTP request.

**Insight then chốt:** một **cron job cPanel chạy trong shell riêng do `crond` spawn**, KHÔNG bị
`disable_functions` của PHP-FPM ràng buộc. Dòng lệnh cron được `/bin/sh` thực thi trực tiếp, nên
`php artisan …` chạy bình thường. → cPorter tách làm **2 ngữ cảnh thực thi**:

| Ngữ cảnh | Làm được gì | Dùng cho |
|---|---|---|
| **Web PHP (đồng bộ)** | filesystem (mkdir/rename/unlink/scandir), `ZipArchive` extract, `symlink`, cURL health check | Toàn bộ pipeline của app **static / WordPress / PHP thuần**: extract → link shared → swap symlink → health check → prune. **Không cần shell.** |
| **Cron shell (bất đồng bộ)** | chạy lệnh shell thật: `php artisan migrate/config:cache/queue:restart`, composer nếu cần, artisan của chính cPorter | **Hooks của app Laravel đích** + queue worker + housekeeping |

### 9.1 Cơ chế Command Runner (driver `cron-worker`)

```
Web request (deploy Laravel)                 Standing cron (mỗi ~1 phút)
──────────────────────────                   ─────────────────────────────
1. extract + link + swap symlink   ┐         a. crond chạy: php <cporter>/current/artisan cporter:run-jobs
2. enqueue "shell jobs":           │            (dòng lệnh này do shell chạy → KHÔNG cần exec trong PHP)
   - php <target>/current/artisan  │         b. runner đọc jobs pending trong DB
     migrate --force               │──────►  c. thực thi từng job bằng shell (qua chính cron command,
   - artisan config:cache          │            không qua exec() của PHP)
3. Deployment.status =             │         d. ghi kết quả (exit code, output) về Job + Deployment
   "activated, hooks_pending"      ┘         e. chạy health check lại → success | failed(+auto-rollback)
```

- **Job queue** lưu trong DB (`jobs` bảng riêng của cPorter, hoặc Laravel queue driver `database`).
- **Runner** = artisan command `cporter:run-jobs` của chính cPorter, được cron gọi. Vì cron command
  do shell chạy, runner có thể dùng shell để thực thi lệnh app đích **kể cả khi PHP `exec` bị chặn** —
  cách chắc ăn nhất: **mỗi job là một dòng lệnh shell mà runner ghi ra rồi để cron kế tiếp chạy**, hoặc
  runner thử `proc_open` (CLI PHP thường nới lỏng hơn FPM — probe riêng cho context CLI).
- **Độ trễ:** hooks chạy trong ≤ chu kỳ cron (khuyến nghị 1 phút; có thể 1 cron/phút gọi liên tục).
  Với app **không có hook** (static/WP/PHP) → deploy **tức thì, đồng bộ**, không đụng cron.

### 9.2 Các driver (theo thứ tự ưu tiên trên host này)

| Driver | Trạng thái host này | Ghi chú |
|---|---|---|
| `none` | ✅ mặc định cho static/WP/PHP | Không cần lệnh shell; toàn bộ chạy trong web PHP. |
| `cron-worker` | ✅ **chính** cho Laravel | Chạy hooks qua cron shell context. Bất đồng bộ. |
| `proc_open` (CLI) | ⚙️ probe | Nếu CLI PHP cho `proc_open`, runner chạy đồng bộ hơn trong cron. |
| `ssh` (phpseclib) | ❔ chỉ khi host bật SSH | Không xác nhận trên host này; để tùy chọn. |
| `manual` | ✅ fallback cuối | Đánh dấu `hooks_pending(manual)`, hiện lệnh cần chạy trên Admin để người dùng tự chạy. |

### 9.3 Capability probe (lúc cài đặt & định kỳ)

cPorter chạy probe và lưu vào Settings, hiển thị ở Admin: `symlink` runtime OK?, `ZipArchive` OK?,
`proc_open` (web & CLI) OK?, quyền ghi các `base_path`, dung lượng đĩa, phiên bản PHP, cron đã cấu hình chưa.

> **Hệ quả roadmap:** vì static/WP/PHP deploy được **hoàn toàn không cần shell**, MVP (Phase 1) làm nhóm
> này trước; Laravel + cron-worker vào Phase 2 (§18).

---

## 10. Scheduler / Cron

- **Một** cron job cPanel (vd mỗi phút) gọi `GET/POST https://deploy.domain/cron/tick` (token nội bộ).
- `cron/tick` xử lý: chạy `queue:work --stop-when-empty` (nếu dùng queue DB), prune release quá hạn,
  timeout deployment treo (dọn lock cũ), retry health-check theo lịch, chạy scheduled task.
- Có thể tạo cron tự động qua **cPanel UAPI** khi setup (nếu khả dụng), hoặc hướng dẫn tạo thủ công.

---

## 11. Storage Abstraction & cPanel FS Adapter

Interface (khái niệm):

```
StorageAdapter:
  putArtifact(stream) : path
  extract(archive, destDir)        # PharData/ZipArchive, chống zip-slip
  symlinkAtomic(target, linkPath)  # ln -sfn = symlink tmp + rename
  linkShared(release, sharedPaths)
  pruneReleases(project, keep)
  writeLock(project) / removeLock(project)
  readCurrentTarget(project)
```

**Jail / bảo mật đường dẫn**: mọi thao tác chỉ được phép trong danh sách `allowed_base_paths`
(các `Project.base_path` + storage của cPorter). Chuẩn hóa `realpath` và từ chối nếu thoát khỏi jail
(chống path traversal, symlink escape).

Cấu trúc adapter cho phép sau này thêm: `SshStorageAdapter`, `S3ArtifactStore`…

---

## 12. Bảo mật (Security)

- **Token/API Key**: sinh ngẫu nhiên, lưu **hash** (không lưu plaintext), hiển thị 1 lần. Có prefix để tra cứu.
- **Scopes**: `read`, `deploy`, `rollback`, `admin`; giới hạn theo project.
- **Webhook**: verify HMAC signature theo provider (GitHub `X-Hub-Signature-256`…).
- **Rate limit** + optional **IP allowlist** cho API deploy.
- **Zip-Slip / Path traversal**: validate mọi entry khi extract; jail đường dẫn (§11).
- **Inode/size cap**: từ chối artifact vượt ngưỡng số file/dung lượng.
- **Audit log** mọi hành động nhạy cảm (deploy, rollback, tạo/thu hồi token).
- **Admin auth**: session + password hash (bcrypt/argon2); tùy chọn 2FA (roadmap).
- **HTTPS bắt buộc** (cPanel AutoSSL / Let's Encrypt do user cấu hình).
- **`.env` & secrets** để trong `shared/`, không nằm trong artifact, không log.

---

## 13. Admin Panel (React SPA)

| Màn hình | Nội dung |
|---|---|
| **Dashboard** | Tổng quan project, deploy gần đây, tỷ lệ success, release đang active, cảnh báo (migration pending, lock treo). |
| **Projects** | CRUD project (base_path, type, docroot, php_binary, hooks, health check, keep_releases). Nút "Deploy"/"Rollback". |
| **Deployments** | Danh sách + trạng thái realtime (poll), timeline các step, log tail. |
| **Releases** | Lịch sử release/project; activate (rollback) 1 release; xem diff version. |
| **Logs** | Log tập trung (deploy + audit), filter theo project/status/time. |
| **Settings** | Capability probe (exec/ssh/zip…), cấu hình global, cron status. |
| **Users** | Quản lý user admin, role. |
| **API Keys / Tokens** | Tạo/thu hồi token, gán scope & project, xem last_used. |

FE gọi cùng API `/api/v1` (dùng session hoặc token admin). Realtime: **polling** cho MVP
(SSE/WebSocket khó trên shared hosting) — poll `deployments/{id}` mỗi 1–2s khi đang chạy.

---

## 14. Deploy chính bản thân cPorter (self-hosting)

- cPorter là Laravel + React → cũng dùng đúng cơ chế release/symlink.
- **Bootstrap lần đầu**: cài thủ công (upload build đầu tiên, tạo `.env` trong `shared/`, chạy migrate,
  set docroot `deploy.domain/current/public`). Đây là chicken-and-egg nên bước 0 làm tay.
- Sau đó: cPorter có thể **self-deploy** qua chính API của nó (project đặc biệt `self`) — cẩn trọng,
  làm ở phase sau.
- CI mẫu (`.github/workflows/deploy.yml`) build artifact rồi gọi API.

**Build artifact cPorter**:
1. `apps/web`: `pnpm build` → copy `dist/` vào `apps/api/public/`.
2. `apps/api`: `composer install --no-dev --optimize-autoloader`.
3. Đóng gói `apps/api/` (đã có `public/` + `vendor/`) thành **`.zip`**, tính sha256.
4. Upload + deploy qua API.

---

## 15. Tech Stack (đề xuất)

| Layer | Lựa chọn | Lý do |
|---|---|---|
| BE | **Laravel 12, PHP 8.2+** | Bạn đã chọn Laravel; ecosystem tốt, Artisan, migration. |
| Auth API | **Laravel Sanctum** | Token hashed, scopes, đơn giản. |
| Queue | **database driver** + cron worker | Shared hosting thường không có Redis. |
| DB | **MySQL (cPanel)** | Sẵn có trên cPanel. |
| FE | **React 19 + Vite + TypeScript** | Bạn đã chọn React; Vite build nhanh, ra static. |
| FE UI kit | **Mantine v9** (core + hooks) + `@tabler/icons-react` + PostCSS preset | UI kit duy nhất — **không Tailwind**. Docs khai thác qua `llms.txt` (xem [docs/FRONTEND.md](FRONTEND.md) + skill `mantine-ui`). |
| FE state/data | React Query 5 + React Router 7 | Chuẩn phổ biến, dễ maintain. |
| Artifact | **ZIP** (`ZipArchive`) | Đã test runtime OK (§2.1). Không dùng tar.gz/PharData. |
| Command exec | **cron-worker** (artisan `cporter:run-jobs` chạy qua cron) | exec/proc_open không dùng được ở web PHP (§9). |
| SSH (optional) | phpseclib | Driver `ssh` — chỉ khi host bật SSH (không xác nhận trên host hiện tại). |
| Test | Pest/PHPUnit (BE), Vitest (FE) | Có **FakeStorageAdapter** để test pipeline không đụng FS thật. |

---

## 16. Cấu hình Project (ví dụ)

```jsonc
{
  "name": "Learn Platform",
  "slug": "learn",
  "base_path": "/home/user/learn.domain",
  "type": "laravel",                 // laravel | static | php | node
  "docroot_subpath": "public",       // current/public
  "php_binary": "/opt/cpanel/ea-php82/root/usr/bin/php",
  "keep_releases": 5,
  "shared_paths": [".env", "storage"],
  "health_check_url": "https://learn.domain/up",
  "hooks": {
    "pre_activate":  ["artisan migrate --force", "artisan config:cache"],
    "post_activate": ["artisan queue:restart"]
  }
}
```

- `type: static` → bỏ qua hooks/command runner, chỉ extract + swap symlink (an toàn nhất trên shared).

---

## 17. Quyết định đã chốt & câu hỏi còn lại

### 17.1 Đã chốt (2026-07-17)

| # | Quyết định | Chốt |
|---|---|---|
| 1 | Command exec cho app đích | **Không có exec/proc_open** ở web PHP → dùng **cron-worker** (§9). Static/rollback không cần shell. |
| 2 | Phạm vi tài khoản | **Single-tenant, cùng 1 cPanel account.** Multi-tenant → roadmap Phase 4. |
| 3 | Database | **MySQL (cPanel).** |
| 4 | App type ở MVP | **static/React SPA, Laravel, WordPress/PHP thuần.** (Node/Passenger → phase sau.) |
| 5 | Artifact format | **ZIP** (§2.1). |

### 17.2 Còn lại — nên làm rõ trước/khi vào code

1. **Kích thước artifact thực tế** dự kiến bao nhiêu MB? < 200MB → single upload cho MVP, chunked để Phase 2;
   hay > 256MB → cần chunked ngay.
2. **WordPress**: chỉ deploy code (theme/plugin/`wp-content`) hay cả core? shared_paths cho WP
   (`wp-content/uploads`, `wp-config.php`) — xác nhận để định nghĩa `type: wordpress` đúng.
3. **Health check URL** cho từng loại app: Laravel 12 có `/up` sẵn; static/WP cần URL nào?
4. **Cron interval** chấp nhận cho hooks Laravel (1 phút?) — ảnh hưởng độ trễ deploy Laravel.
5. **CI đầu tiên tích hợp** (GitHub Actions?) để làm mẫu workflow + SDK trước.

---

## 18. Roadmap theo phase

**Phase 0 — Foundation**
- Scaffold monorepo (Laravel + React + build script + CI mẫu).
- Domain model + migrations. Auth (Sanctum) + API key. Capability probe.

**Phase 1 — MVP Deploy (static/SPA trước)**
- Storage Abstraction + cPanel FS Adapter (symlink, extract, prune, lock).
- Pipeline đầy đủ cho `type: static`: upload → verify → extract → activate → health check → prune.
- Rollback (symlink). Admin: Projects, Deployments, Releases, Logs cơ bản.

**Phase 2 — Laravel target + Hooks**
- Command Runner (exec/ssh/manual) + hooks (migrate/cache/queue).
- Chunked upload. Idempotency. Auto-rollback.

**Phase 3 — Hoàn thiện Admin & Scheduler**
- Dashboard, Users, Tokens UI. Cron scheduler (queue worker, prune, timeout). Audit log UI.

**Phase 4 — Ecosystem (tương lai)**
- GitHub Action chính thức, JS SDK, PHP SDK, CLI, Plugin/Adapter API, đa server/đa account.

---

## 19. Rủi ro & giảm thiểu (tóm tắt)

| Rủi ro | Mức | Giảm thiểu |
|---|---|---|
| `exec` bị chặn → không migrate được | Cao | Command Runner đa driver + fallback manual; ưu tiên static trước (§9) |
| Timeout khi upload/extract artifact lớn | TB | Chunked upload, extract theo bước, tăng limit qua `.htaccess`/php.ini nếu cho phép |
| Inode/disk quota đầy | TB | Không ship node_modules, prune release, cap file count |
| Symlink không được phép/không follow ở docroot | TB→Thấp | Probe khi setup; fallback copy-swap nếu host cấm symlink |
| Deploy trùng / lock treo | TB | Lock atomic O_EXCL + TTL, cron dọn lock quá hạn |
| Rollback làm hỏng dữ liệu do migration | Cao | Rollback code-only mặc định, cảnh báo rõ (§8) |
```
