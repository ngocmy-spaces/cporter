# BE 01 — Setup môi trường local

🎯 **Mục tiêu:** Dựng môi trường chạy backend trên máy, có **2 cách**:
- **Cách A — Docker Compose** (khuyên dùng): một lệnh, có sẵn PHP 8.3 + MySQL 8 + cron-worker giống cPanel.
- **Cách B — Cài tay** (hiểu rõ từng mảnh): tự cài PHP/Composer/MySQL rồi `php artisan serve`.

Xong bài này bạn gọi được `GET /api/v1/health` trả `200` và đăng nhập được admin.

---

## 📚 Kiến thức nền

- Backend nằm ở **`apps/api`**, là một app **Laravel 12** chuẩn (có `artisan`, `composer.json`, `routes/`).
- Nó là một phần của **monorepo pnpm** — nhưng BE *không* cần pnpm để chạy; pnpm chỉ cho FE và các package JS.
- DB mặc định **MySQL**. Session/cache/queue đều để driver **`database`** (SPEC quyết định vậy vì cPanel không
  có Redis ổn định) → cần chạy migration trước khi dùng.

---

## Cách A — Docker Compose (khuyên dùng)

Mô phỏng gần đúng cPanel: `api`/`worker` chạy **PHP 8.3**, DB **MySQL 8**, có service `worker` chạy
`schedule:work` (đóng vai cron của cPanel).

### A.1 — Yêu cầu
Chỉ cần **Docker Desktop** (Windows/Mac) hoặc Docker Engine + compose plugin.

### A.2 — Chạy
Từ **thư mục gốc repo** (chỗ có `docker-compose.yml`):

```bash
docker compose up --build
```

Lần đầu sẽ chậm (build image PHP, cài composer + pnpm deps). Khi thấy log ổn định, mở:

- SPA (Vite): <http://localhost:5173>
- API trực tiếp: <http://localhost:8000/api/v1/health>

Đăng nhập: **`admin@cporter.local`** / **`password`**.

### A.3 — Có gì trong compose
Tham chiếu [`docker-compose.yml`](../../docker-compose.yml):

| Service | Cổng (host) | Vai trò |
|---|---|---|
| `db` | `3307` → 3306 | MySQL 8, database `cporter`, user `cporter`/`secret` |
| `api` | `8000` | Laravel (`php artisan serve`), PHP 8.3 |
| `worker` | — | Chạy `schedule:work` → finalize deploy Laravel, queue, housekeep |
| `web` | `5173` | Vite dev, proxy `/api` → `http://api:8000` |

Biến môi trường quan trọng đã set sẵn trong `x-api-env`:
- `CPORTER_ALLOWED_BASE_PATHS=/srv/sites` → **jail** là `/srv/sites` (volume `sites` chia sẻ giữa `api` và
  `worker`). Khi tạo project để test, đặt `base_path=/srv/sites/<tên>` (thư mục tự tạo).
- `CPORTER_COMMAND_DRIVER=cron-worker` → hook chạy ở `worker`, không phải trong request web.

### A.4 — Lệnh hay dùng

```bash
# Chạy artisan bên trong container api
docker compose exec api php artisan migrate:status
docker compose exec api php artisan test

# Tail log
docker compose logs -f api worker

# Dừng (giữ data)
docker compose down
# Dừng + xoá sạch DB/volume
docker compose down -v
```

> ✅ Đến đây nếu `GET http://localhost:8000/api/v1/health` trả `{"status":"ok",...}` là xong Cách A. Có thể
> bỏ qua Cách B.

---

## Cách B — Cài tay (không Docker)

Hiểu rõ từng mảnh, chạy nhanh hơn khi dev thuần backend.

### B.1 — Cài công cụ

| Tool | Version | Ghi chú |
|---|---|---|
| PHP | 8.2+ (host đích 8.3) | cần các extension: `pdo_mysql`, `mbstring`, `openssl`, `zip`, `fileinfo`, `curl` |
| Composer | 2.x | quản lý dependency PHP |
| MySQL | 5.7+ / 8 | tạo sẵn database `cporter` |

Kiểm tra nhanh:

```bash
php -v                    # ≥ 8.2
php -m | grep -E "pdo_mysql|mbstring|zip|openssl|curl"   # phải thấy đủ
composer --version        # 2.x
```

> 💡 **Windows:** dễ nhất là cài [Laragon](https://laragon.org/) hoặc [XAMPP] (có sẵn PHP + MySQL), hoặc PHP
> từ [php.net](https://windows.php.net/download/) rồi bật extension trong `php.ini`. Nhớ bật `extension=zip`
> và `extension=pdo_mysql`.

### B.2 — Tạo database

```sql
CREATE DATABASE cporter CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

(Hoặc dùng user `root` không mật khẩu như `.env.example` mặc định.)

### B.3 — Cài dependency Laravel

```bash
cd apps/api
composer install
```

### B.4 — Tạo file `.env`
Copy từ mẫu rồi sinh `APP_KEY`:

```bash
cp .env.example .env
php artisan key:generate
```

Mở `apps/api/.env`, chỉnh phần database cho khớp máy bạn (tham chiếu
[`.env.example`](../../apps/api/.env.example)):

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cporter
DB_USERNAME=root
DB_PASSWORD=

# SPA gọi cùng origin — để localhost dev
SANCTUM_STATEFUL_DOMAINS=localhost:5173,localhost:8000

# Admin đầu tiên (DatabaseSeeder dùng)
CPORTER_ADMIN_EMAIL=admin@cporter.local
CPORTER_ADMIN_PASSWORD=password

# JAIL: để trống trên máy dev cũng chạy, nhưng muốn test deploy thì trỏ tới 1 thư mục thật, vd:
#   CPORTER_ALLOWED_BASE_PATHS=/home/you/cporter-sites   (Linux/Mac)
#   CPORTER_ALLOWED_BASE_PATHS=C:\Users\you\cporter-sites (Windows)
CPORTER_ALLOWED_BASE_PATHS=
```

> ⚠️ **Jail trên Windows:** cơ chế deploy atomic dùng **symlink** (`activate`). Trên Windows cần quyền tạo
> symlink (bật Developer Mode hoặc chạy terminal as admin). Vì vậy để *chạy thử deploy thật*, Docker (Linux)
> mượt hơn nhiều. Phần CRUD/API/admin thì Windows chạy bình thường.

### B.5 — Chạy migration + seed

```bash
php artisan migrate --seed
```

- `migrate` dựng toàn bộ bảng (users, projects, releases, artifacts, deployments, api_keys, audit_logs,
  settings… — xem [`apps/api/database/migrations`](../../apps/api/database/migrations)).
- `--seed` chạy `DatabaseSeeder` tạo admin đầu tiên từ `CPORTER_ADMIN_EMAIL`/`PASSWORD`.

### B.6 — Chạy server

```bash
php artisan serve        # http://localhost:8000
```

Thử health:

```bash
curl http://localhost:8000/api/v1/health
# → {"status":"ok", ...}
```

### B.7 — (tuỳ chọn) cron-worker local
App Laravel đích cần cron-worker để finalize (bài [12](12-command-runner-hooks-cron.md)). Trên máy dev, chạy một
tiến trình đóng vai cron:

```bash
php artisan schedule:work
```

Nó tick lịch: `cporter:run-jobs` + `queue:work` + `cporter:housekeep` + `cporter:check-health`.
Với queue driver `sync`, job có thể chạy inline trong request để tiện test; cấu hình Docker/production dùng
queue worker để xử lý deploy bất đồng bộ, kể cả project không có hooks.

---

## ✅ Kiểm tra

Dù dùng cách nào, các mốc sau phải đạt:

```bash
# 1) Health endpoint
curl http://localhost:8000/api/v1/health            # 200, {"status":"ok"}

# 2) Migration đã chạy
php artisan migrate:status                           # (Docker: docker compose exec api php artisan migrate:status)

# 3) Test suite xanh
php artisan test                                     # Pest — toàn bộ pass

# 4) Đăng nhập admin được (qua SPA http://localhost:5173 hoặc gọi API /auth/login)
```

## 📌 Checklist "xong khi nào"

- [ ] `GET /api/v1/health` trả `200`.
- [ ] `php artisan migrate:status` thấy tất cả migration đã `Ran`.
- [ ] `php artisan test` xanh (xác nhận môi trường ổn).
- [ ] Đăng nhập được `admin@cporter.local` / `password`.
- [ ] (Nếu định test deploy thật) đã trỏ `CPORTER_ALLOWED_BASE_PATHS` tới một thư mục tồn tại.

👉 Tiếp theo: [02 — Khởi tạo Laravel và cấu hình](02-khoi-tao-laravel-cau-hinh.md) — đọc cấu trúc app,
bootstrap, config và cách Laravel nhận request.
