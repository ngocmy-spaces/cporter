# cPorter

Self-hosted deploy orchestrator chạy trên **cPanel shared hosting**. Cài như một web app bình thường
(monorepo **React + Laravel**, 1 folder / 1 domain), nhưng quản lý và deploy atomic (release + symlink,
rollback tức thì) cho các domain khác cùng tài khoản cPanel — điều khiển qua HTTP API từ bất kỳ CI nào.

## Cấu trúc monorepo

```
cporter/
├── apps/
│   ├── api/          # Laravel 12 — Deploy API + Core Engine + Admin API
│   └── web/          # React + Vite + TS — Admin Panel SPA
├── build/            # build-artifact.mjs → gộp FE+BE thành 1 file .zip deploy
├── docs/SPEC.md      # Đặc tả kỹ thuật đầy đủ
└── TASKS.md          # Chia task theo phase
```

Khi deploy: `apps/web` build ra static → copy vào `apps/api/public`; Laravel phục vụ `/api/v1/*` là JSON
và fallback các route khác về SPA. Docroot cPanel trỏ vào `deploy.domain/current/public`.

## Yêu cầu môi trường dev

| Tool | Version | Ghi chú |
|---|---|---|
| Node | ≥ 20 (đang dùng 24) | cho `apps/web` |
| pnpm | ≥ 11 | package manager |
| PHP | 8.2+ (target host: 8.3) | cho `apps/api` — **cần cài để chạy BE** |
| Composer | 2.x | cài dependency Laravel |
| MySQL | 5.7+/8 | DB của cPorter |

## Bắt đầu (dev)

```bash
# 1) Cài toàn bộ JS deps (root + apps/web) + build script
pnpm install

# 2) Chạy Admin SPA (Vite dev server, proxy /api → http://localhost:8000)
pnpm dev:web

# 3) Backend Laravel (khi đã có PHP + Composer)
cd apps/api
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve            # http://localhost:8000
```

## Build artifact deploy

```bash
# CI: composer install --no-dev trong apps/api trước, rồi:
pnpm build:artifact          # → build/out/cporter-<version>.zip + in sha256
```

## Tài liệu

- 📄 [Đặc tả kỹ thuật (SPEC)](docs/SPEC.md)
- ✅ [Task breakdown theo phase](TASKS.md)

## Trạng thái

Phase 0 — dựng khung sườn monorepo. Xem [TASKS.md](TASKS.md).
