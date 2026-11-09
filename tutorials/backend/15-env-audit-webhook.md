# BE 15 — Env vars, audit và webhook

🎯 **Mục tiêu:** quản lý cấu hình bí mật của project mà không lưu plaintext trong DB/log, ghi audit cho hành
động quản trị và xác thực request webhook bằng chữ ký.

## 1. Mã hóa env vars ở database

`Project.env_vars` nhận danh sách key/value, chuẩn hóa key và mã hóa JSON bằng Laravel `Crypt` trước khi lưu.
Giải mã cần `APP_KEY`, vì thế mất/đổi key sẽ khiến ciphertext cũ không đọc được.

```php
'env_vars' => Crypt::encryptString(json_encode($normalized)),
```

Mẫu khái niệm trên được rút gọn; dùng accessor/cast `Project::envVars()` trong implementation để dữ liệu
serialize đúng. Model ẩn field khỏi `toArray()`; endpoint trả plaintext là admin-only và audit chỉ ghi tên key,
không ghi value.

## 2. Render `shared/.env` có ownership

`EnvFileRenderer` quote/escape giá trị trước khi ghi file. Storage adapter nhận marker quản lý ở đầu file:

- file không tồn tại → có thể tạo;
- file có marker của cPorter → được cập nhật;
- file tồn tại nhưng không có marker → không ghi đè mặc định;
- operator chủ động adopt → ghi đè có kiểm soát và audit.

Environment của project được đưa vào `shared/` để tồn tại qua nhiều release. Không đưa `.env` vào ZIP/repository
hoặc response project đọc chung.

## 3. Audit log

`AuditLogger::record(action, subject, meta, actor)` chuẩn hóa actor/subject/IP và lưu entry. Ghi audit khi có
thao tác nhạy cảm (project, API key, env, user, deployment, webhook) nhưng tránh metadata chứa secret hoặc
toàn bộ request body.

## 4. Webhook chữ ký

GitHub dùng HMAC-SHA256 trên raw body với `X-Hub-Signature-256`; GitLab dùng `X-Gitlab-Token`. So sánh token/
signature bằng `hash_equals`, không decode/serialize JSON lại trước khi tính HMAC. Nếu webhook secret chưa cấu
hình thì endpoint trả `503`, signature sai trả `401`.

Các lớp cần đọc:

- `apps/api/app/Domain/Deploy/EnvFileRenderer.php`
- `apps/api/app/Domain/Audit/AuditLogger.php`
- `apps/api/app/Http/Controllers/Api/WebhookController.php`
- `apps/api/tests/Feature/ProjectEnvApiTest.php` và `Phase3Test.php`

## 5. Kiểm tra

```bash
php artisan test --filter=ProjectEnvApiTest
php artisan test --filter=Phase3Test
```

Kiểm tra DB giữ ciphertext, model JSON không lộ env, viewer bị cấm, audit metadata không có values và HMAC
raw-body đúng/sai cho ra status phù hợp.

👉 Tiếp theo: [16 — Kiểm thử Backend](16-kiem-thu-backend.md).
