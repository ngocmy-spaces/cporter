# BE 12 — CommandRunner, hooks và cron worker

🎯 **Mục tiêu:** chạy lệnh của app đích ở shell context thay vì request web; giữ rõ working directory, timeout,
output và môi trường biến hệ thống.

## 1. Vì sao có CommandRunner

cPanel có thể chặn `proc_open` ở PHP-FPM nhưng cho phép PHP CLI chạy qua cron. Vì thế HTTP request chỉ stage
deploy và chuyển sang `hooks_pending`; `cporter:run-jobs` hoàn tất deploy trong cron context.

`CommandRunner` là interface; `ProcessCommandRunner` là implementation Symfony Process. `AppServiceProvider`
bind interface sang concrete adapter để Domain không phụ thuộc cách lệnh được thực thi.

## 2. Hook stages

Hooks cấu hình ở project theo stages:

- `pre_activate`: chạy khi release mới chưa active. Lỗi giữ release cũ đang chạy.
- `post_activate`: chạy sau activation. Lỗi được đưa qua rollback policy của trigger `post_activate_hook`.

Mỗi hook chạy trong release directory, có timeout và kết quả exit/output được ghi vào deployment steps.
Trong cPanel hãy dùng PHP CLI path tuyệt đối; `php` có thể resolve tới binary không phải CLI mong muốn.

## 3. Chặn rủi ro hook

Hook là lệnh tùy ý do operator cấu hình, nên chỉ admin được sửa. `HookGuard` chặn các lệnh nguy hiểm như
`migrate:fresh` cả lúc lưu lẫn ngay trước lúc thực thi. Thêm guard không thay thế việc review quyền của người
dùng có khả năng cấu hình hooks.

`ProcessCommandRunner` dùng allow-list cho biến môi trường được kế thừa từ cPorter. Mục tiêu là tránh truyền
`APP_KEY`, DB credentials và cron secrets của cPorter vào tiến trình app đích. Chỉ thêm passthrough biến môi
trường có lý do cụ thể; không dùng deny-list ngắn dễ bỏ sót secrets mới.

## 4. Lệnh worker và scheduler

`cporter:run-jobs` chọn deployment `hooks_pending`, gọi `DeployEngine::finalize()` và sau đó thử drain FIFO.
Scheduler định nghĩa lịch trong `apps/api/routes/console.php`; bài 14 giải thích housekeeping/heartbeat.
Nếu hosting chỉ cho cron mỗi 5 phút, kiểm tra hướng dẫn `cporter:work` trong
`docs/DEPLOYMENT-CPANEL.md`.

## 5. Kiểm tra local

```bash
php artisan test --filter=LaravelDeployTest
php artisan test --filter=HookEnvIsolationTest
php artisan test --filter=HookGuardTest
php artisan cporter:run-jobs --help
```

Trong Docker có thể quan sát API và worker riêng:

```bash
docker compose logs -f api worker
```

Không chạy hook phá hủy dữ liệu trên project thật chỉ để xác nhận worker hoạt động.

👉 Tiếp theo: [13 — Chunked upload và FIFO deploy](13-chunked-upload-fifo.md).
