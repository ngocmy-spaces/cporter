# BE 11 — Health check và rollback

🎯 **Mục tiêu:** kiểm tra bản đang chạy sau activation, phân biệt rollback thủ công với auto-rollback và hiểu
policy rollback theo từng trigger.

## 1. Health check

Project có thể cấu hình `health_check_url`. `HealthChecker::check()` gọi URL với timeout/retry; `ProjectHealthMonitor`
lưu trạng thái gần nhất để Dashboard/Projects đọc được mà không chạy probe mới theo mỗi lần xem trang.

Phân biệt hai đường kiểm tra:

- **Deploy gate:** ngay sau khi activate; kết quả ảnh hưởng trạng thái deployment.
- **Monitor định kỳ:** scheduler kiểm tra project đang hoạt động và cập nhật `health_status`.

Đừng coi status `unknown` là healthy; nó có nghĩa chưa có tín hiệu đủ tin cậy.

## 2. Rollback thủ công

`RollbackEngine` chọn release trước đó hoặc release operator chỉ định, xác nhận release directory còn tồn tại,
rồi đổi `current`. Đây là rollback code-only: không chạy lại migration ngược và không hoàn tác dữ liệu dùng chung.
API rollback được bảo vệ bởi scope `rollback`; admin có route activate release riêng.

Trước khi thay đổi rollback, kiểm tra guard tránh chạy trong lúc deploy đang thực sự `running`/`hooks_pending`.
Deployment chỉ ở trạng thái `queued` không nhất thiết phải chặn operator.

## 3. Auto-rollback opt-in

Project chọn rõ trigger trong `auto_rollback_on`, hiện có `health_check` và `post_activate_hook`. Khi trigger
xảy ra sau khi release mới đã live:

1. nếu policy không bật trigger, giữ release mới nhưng đánh dấu deployment failed/health unhealthy;
2. nếu bật, tìm một release trước hợp lệ còn trên disk;
3. nếu tìm thấy, activate lại release đó và ghi `rolled_back`;
4. nếu không có đích rollback hợp lệ, không lặp vòng rollback — giữ trạng thái lỗi để operator xử lý.

Rollback không phải database transaction; artifact/DB migrations trong app đích có thể đã thay đổi. Ghi chú
rõ điều này trong vận hành.

## 4. Kiểm tra

```bash
php artisan test --filter=HealthCheckTest
php artisan test --filter=RollbackTest
php artisan test --filter=ProjectHealthTest
```

Test URL unreachable, trigger bật/tắt, release cũ mất trên disk và rollback thủ công trong lúc deployment
đang active.

👉 Tiếp theo: [12 — CommandRunner, hooks và cron worker](12-command-runner-hooks-cron.md).
