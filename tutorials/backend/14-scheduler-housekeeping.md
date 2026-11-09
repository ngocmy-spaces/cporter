# BE 14 — Scheduler, housekeeping và retention

🎯 **Mục tiêu:** vận hành việc finalize deploy, xử lý queue, health checks và dọn dữ liệu định kỳ trên host
không có daemon/systemd.

## 1. Scheduler là lịch; cron là bộ kích hoạt

Laravel schedule khai báo trong `apps/api/routes/console.php`. Trên cPanel, cron gọi `schedule:run` mỗi phút:

```cron
* * * * * cd /home/USER/cporter.domain/current && /path/to/php artisan schedule:run >> /dev/null 2>&1
```

Dùng PHP CLI tuyệt đối đã xác nhận trên host. Nếu host giới hạn cron ở mỗi 5 phút, xem phương án
`cporter:work` trong `docs/DEPLOYMENT-CPANEL.md`; không cấu hình đồng thời hai cơ chế worker nếu hướng dẫn
triển khai không yêu cầu.

## 2. Các tác vụ được lập lịch

- `cporter:run-jobs`: finalize deployment hooks-pending.
- `queue:work --stop-when-empty`: chạy job rồi thoát.
- `cporter:housekeep`: fail deploy treo, nhả lock, drain backlog và prune.
- `cporter:check-health`: cập nhật project health.
- `cporter:probe-binaries`: dò binary trong cron shell.

`withoutOverlapping()` ngăn lịch cùng tên chạy chồng trong khi tiến trình trước còn giữ lock.

## 3. Housekeeping và retention

`ArtifactPruner` chỉ xóa ZIP khi deployment ổn định và file không còn cần cho live/in-flight release.
`ReleasePruner` và storage adapter phải bảo vệ release active. Orphan sweep áp dụng minimum age để tránh
xóa file mới được upload nhưng chưa kịp gắn DB row.

Housekeeping có thể tiếp tục sweep project khác khi một project lỗi; lỗi từng project phải được log/heartbeat
để operator phát hiện, không biến lỗi dọn dẹp thành trạng thái deploy giả.

## 4. Heartbeat và kiểm tra vận hành

System endpoints đọc cron heartbeat, artifact storage heartbeat, queue/retention signal. Nếu heartbeat cũ,
kiểm tra cron thật sự được cài, PHP binary, thư mục working directory và log trước khi kích hoạt manual cleanup.

```bash
php artisan schedule:list
php artisan cporter:housekeep
php artisan cporter:check-health
```

Chỉ chạy lệnh xóa thủ công sau khi xác nhận môi trường và dữ liệu đích.

👉 Tiếp theo: [15 — Env vars, audit và webhook](15-env-audit-webhook.md).
