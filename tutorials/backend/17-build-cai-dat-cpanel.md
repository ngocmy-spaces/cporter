# BE 17 — Đóng gói và cài lần đầu lên cPanel

🎯 **Mục tiêu:** tạo artifact production có đủ Laravel `vendor/`, cấu hình máy chủ theo nguyên tắc an toàn,
rồi khởi tạo app lần đầu.

## 1. Build dependencies trước khi đóng gói

Build script `build/build-artifact.mjs` yêu cầu Composer dependencies đã cài cho production:

```bash
cd apps/api
composer install --no-dev --optimize-autoloader --no-interaction
cd ../..
pnpm build:artifact
```

Backend là phần cần học ở đây; script đồng thời đóng gói SPA đã build vào `apps/api/public`. Không sửa/chạy
phần React trong tutorial Backend, nhưng artifact cuối vẫn phải chứa static UI nếu muốn cài toàn bộ cPorter.
Script xuất ZIP và SHA-256; artifact loại trừ `.env`, tests và runtime storage.

Đọc [docs/ARTIFACT.md](../../docs/ARTIFACT.md) và `build/build-artifact.mjs` để xác nhận nội dung ZIP, không
tự đóng gói theo một layout khác.

## 2. Chuẩn bị cPanel

Trước khi upload:

1. tạo domain/subdomain cho cPorter và Document Root tới `current/public`;
2. chọn PHP 8.3 (hoặc version tương thích được project hỗ trợ);
3. tạo MySQL database/user và cấp quyền tối thiểu cần thiết;
4. chọn một root hẹp cho `CPORTER_ALLOWED_BASE_PATHS`;
5. chuẩn bị PHP **CLI** path tuyệt đối cho cron.

Hosting PHP web và PHP CLI có thể khác binary/version. Xác minh CLI bằng `php -v` trong Terminal/cPanel trước
khi dùng cho cron.

## 3. Bootstrap lần đầu

Tạo cấu trúc `releases/`, `shared/`, giải nén artifact vào release mới, đặt `.env` production trong shared,
liên kết `.env` và storage vào release, rồi chạy từ thư mục release:

```bash
PHP=/opt/cpanel/ea-php83/root/usr/bin/php
$PHP artisan key:generate --force
$PHP artisan migrate --force --seed
$PHP artisan config:cache
```

Lệnh minh họa theo [docs/DEPLOYMENT-CPANEL.md](../../docs/DEPLOYMENT-CPANEL.md); thay path, DB credentials,
domain, admin password và jail theo tài khoản thật. Không commit `.env`, APP_KEY, DB password hoặc cron secret.

Chỉ seed account mặc định sau khi đã thiết lập thông tin admin production an toàn. Không dùng password demo
local ngoài local.

## 4. Activate và smoke test

Sau khi xác nhận release/bootstrap hoàn tất, trỏ `current` vào release mới theo hướng dẫn deploy. Mở
`https://<domain>/api/v1/health`; xác nhận TLS, response JSON, admin login, DB kết nối và static admin shell.

Đặt Document Root đúng thư mục; cPorter không tự sửa Apache/LiteSpeed vhost.

## Checklist trước khi nhận deploy

- [ ] ZIP chứa `vendor/`, không chứa `.env` hay runtime artifacts.
- [ ] APP_KEY và DB credentials riêng cho production.
- [ ] `CPORTER_ALLOWED_BASE_PATHS` giới hạn vào các folder quản lý.
- [ ] PHP CLI đúng version; migration hoàn tất.
- [ ] Health endpoint và admin login hoạt động.

👉 Tiếp theo: [18 — Cron và vận hành production](18-van-hanh-cap-nhat.md).
