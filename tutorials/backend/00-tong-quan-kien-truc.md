# BE 00 — Tổng quan kiến trúc Backend

🎯 **Mục tiêu:** Trước khi viết dòng code nào, hiểu *vì sao* backend cPorter được thiết kế như vậy. Những
ràng buộc của môi trường cPanel quyết định gần như mọi lựa chọn kỹ thuật phía sau — nắm được chúng thì các
bài sau sẽ rất logic.

---

## 1. Bài toán

cPorter chạy trên **cPanel shared hosting** và phải deploy app cho các domain khác trong cùng tài khoản.
Shared hosting có 3 ràng buộc nghiệt ngã:

1. **Web PHP KHÔNG chạy được shell.** Hàm `exec/shell_exec/proc_open` thường bị tắt trong ngữ cảnh web
   (`mod_php`/PHP-FPM). Nghĩa là từ một HTTP request, ta **không** chạy được `composer install`,
   `php artisan migrate`, `npm ci`…
2. **Không có root, không có systemd, không daemon.** Thứ duy nhất chạy nền được là **cron** của cPanel.
3. **`open_basedir` thường TẮT.** Không có hàng rào hệ thống chặn truy cập file ngoài thư mục → ta phải tự
   dựng "nhà tù" đường dẫn (path jail) ở tầng ứng dụng.

> 📌 **3 quyết định cốt lõi sinh ra từ đây** (SPEC §17.1):
> - **Deploy atomic bằng symlink:** mỗi lần deploy giải nén vào `releases/<timestamp>/` rồi trỏ symlink
>   `current` sang. Đổi symlink là thao tác nguyên tử → không có downtime, rollback = trỏ lại symlink cũ.
> - **"No exec in web PHP → cron-worker":** request web chỉ *tạo việc* (ghi DB, nhận upload). Mọi thứ cần
>   shell (hook `composer install`, `artisan migrate`) để **một cron-worker** chạy sau, trong ngữ cảnh
>   shell (nơi `proc_open` sống).
> - **Path jail tự quản:** vì `open_basedir` tắt, cPorter tự chặn path traversal / symlink escape bằng class
>   `PathJail` (bài [06](06-path-jail-settings.md)).

## 2. Hai luồng deploy

Có **2 loại app đích**, quyết định deploy chạy *đồng bộ* hay *bất đồng bộ*:

```
App không có hooks                       App Laravel (có hooks)
(không cần shell)                        (cần shell: migrate, cache…)
─────────────────────────────           ──────────────────────────────
POST deployment                          POST deployment
  → enqueue job                            → enqueue job
  → worker: extract, link, validate        → worker: extract, link, validate
  → activate + health check                → hooks_pending, giữ deploy lock
  → prune                                  → cron-worker: pre-hooks
  → success  ✅                             → activate → post-hooks + health
                                           → prune → success/rollback policy ✅
```

- Cả hai loại project đều đi qua queue/job. Với driver `sync` (tests/local), job có thể chạy ngay trong
  request gọi; production dùng database queue do scheduler/worker xử lý.
- App không có hooks chỉ không cần shell; app Laravel cần cron shell để finalize hooks. Khác biệt này không
  đồng nghĩa với "có queue / không có queue".

## 3. Các tầng (layers) của backend

```
┌─────────────────────────────────────────────────────────────┐
│ HTTP (routes/api.php)                                         │
│   /api/v1/*   — CI API (API key + scope, tùy endpoint)        │
│   /api/v1/*   — Admin API (same-origin session + CSRF)        │
├─────────────────────────────────────────────────────────────┤
│ Controllers (app/Http/Controllers/Api)                        │
│   mỏng: validate request → gọi Domain → trả JSON              │
├─────────────────────────────────────────────────────────────┤
│ Domain (app/Domain) — "engine", không biết gì về HTTP         │
│   Deploy/   DeployEngine, RollbackEngine, HealthChecker,      │
│             DeployDispatcher, StepRunner, EnvFileRenderer…    │
│   Auth/     ApiKeyService                                     │
│   Storage/  PathJail                                           │
│   System/   capability probe, heartbeat                       │
│   Audit/    AuditLogger                                       │
├─────────────────────────────────────────────────────────────┤
│ Adapters (app/Adapters) — ranh giới với thế giới ngoài        │
│   Storage/  CpanelFilesystemAdapter (file, symlink, lock)     │
│   Command/  ProcessCommandRunner    (chạy shell cho hook)     │
├─────────────────────────────────────────────────────────────┤
│ Models (app/Models) + Enums (app/Enums) + MySQL               │
│   Project, Release, Artifact, Deployment, ApiKey, AuditLog…   │
└─────────────────────────────────────────────────────────────┘
```

**Nguyên tắc vàng:** Domain/Engine **không gọi thẳng** `file_put_contents`, `symlink`, `exec`. Nó gọi qua
**interface Adapter** (`StorageAdapter`, `CommandRunner`). Nhờ vậy:
- Test được bằng adapter giả / thư mục tạm.
- Đổi cPanel → SSH/S3 sau này chỉ cần viết adapter mới, engine không đổi.

## 4. Mô hình dữ liệu (nhìn trước)

Các bảng nghiệp vụ chính (bài [03](03-domain-model-migrations.md) sẽ phân tích):

```
Project ──< Deployment >── Artifact        Project ──< Release
  │           (1 lần deploy)  (file .zip)     │         (1 phiên bản đã giải nén trên đĩa)
  │                                           └ current symlink trỏ tới 1 Release "active"
  ├─ base_path   (trong jail)
  ├─ type        (static | laravel | wordpress | php | node)
  ├─ hooks       (pre/post deploy commands)
  ├─ env_vars    (mã hoá at-rest)
  └─ health_status

ApiKey   — "cpk_…", scope (read/deploy/rollback/admin), gắn 1 project hoặc toàn hệ thống
AuditLog — mọi hành động ghi (ai, làm gì, lên cái gì, IP, khi nào)
Setting  — lưu kết quả capability probe (ext/function/symlink… của host)
User     — admin/viewer, đăng nhập session cho SPA
```

## 5. Hai cơ chế xác thực song song

Đây là điểm dễ nhầm, nhớ kỹ:

| | **Deploy API** (`/api/v1/*`) | **Admin API** (`/api/v1/*`) |
|---|---|---|
| Ai gọi | CI/CLI/SDK/MCP (máy) | Trình duyệt của admin (người) |
| Xác thực | Header `Authorization: Bearer cpk_…` | Session cookie same-origin (Sanctum) |
| Phân quyền | **scope** của key (`read`/`deploy`/`rollback`/`admin`) | **role** của user (`admin`/`viewer`) |
| Middleware | `apikey` + `scope:deploy` | `auth` + `role:admin` |

Bài [04](04-session-dang-nhap-role.md) đi sâu session/role; bài
[05](05-api-key-scope-capability.md) giải thích API key và scope.

## 6. Cron: mạch đập của hệ thống

Web PHP không chạy nền được, nên **một dòng cron duy nhất** của cPanel kéo mọi thứ:

```cron
* * * * * cd /home/<user>/cporter.domain/current && php artisan schedule:run >> /dev/null 2>&1
```

`schedule:run` (định nghĩa trong `routes/console.php`) fan-out ra:
- `cporter:run-jobs` — finalize các deploy Laravel đang `hooks_pending`.
- `queue:work` — giải nén artifact (job nền).
- `cporter:housekeep` — timeout deploy treo, nhả lock kẹt.
- `cporter:check-health` — giám sát sức khoẻ project định kỳ.

Bài [12](12-command-runner-hooks-cron.md) và [14](14-scheduler-housekeeping.md) dựng phần này.

## 7. Thứ tự build (và lý do)

```
Nền tảng            02 Laravel → 03 domain model
Auth / an toàn      04 session → 05 API key → 06 path jail
Deploy core         07 storage → 08 project → 09 API → 10 pipeline → 11 health/rollback
Worker / vận hành   12 hooks → 13 chunk/FIFO → 14 scheduler → 15 env/audit/webhook
Đưa vào production  16 testing → 17 build/cài → 18 vận hành/cập nhật
```

Thứ tự tutorial được gom theo luồng học. Tiến độ release theo phase lịch sử được ghi trong
[`../TASKS.md`](../../TASKS.md); worker/queue behavior cần đọc cùng implementation hiện tại.

---

## ✅ Kiểm tra hiểu bài

Trả lời được 3 câu này là nắm chắc nền tảng:
1. Vì sao không thể chạy `composer install` ngay trong request web? → Giải pháp cPorter là gì?
2. Deploy "atomic" đạt được bằng cách nào? Rollback khác gì?
3. App tĩnh và app Laravel đi theo luồng deploy nào khác nhau, vì sao?

## 📌 Checklist "xong khi nào"

- [ ] Hiểu 3 ràng buộc của cPanel và 3 quyết định cốt lõi sinh ra từ chúng.
- [ ] Phân biệt app không hook với app cần hook shell; nhận biết queue driver quyết định sync hay async.
- [ ] Nắm 4 tầng: HTTP → Controller → Domain → Adapter, và vì sao Domain không gọi thẳng FS/shell.
- [ ] Phân biệt 2 cơ chế auth: API key + scope vs session + role.

👉 Tiếp theo: [01 — Setup local](01-setup-local.md).
