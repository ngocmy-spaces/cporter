# BE 18 — Cron và vận hành production

🎯 **Mục tiêu:** giữ worker/scheduler hoạt động trên hosting không có daemon, giám sát sức khỏe và nâng phiên
bản Backend mà không làm mất DB, env hoặc release đang chạy.

## 1. Cài cron

Phương án chuẩn gọi Laravel scheduler mỗi phút (dùng absolute path PHP CLI):

```cron
* * * * * cd /home/USER/cporter.domain/current && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

Nếu host giới hạn cadence, dùng hướng dẫn `cporter:work` trong
[docs/DEPLOYMENT-CPANEL.md](../../docs/DEPLOYMENT-CPANEL.md). Dùng một cấu hình phù hợp; không tạo cron chồng
nhau khiến nhiều worker cùng xử lý tác vụ.

## 2. Theo dõi hoạt động

Theo dõi:

- timestamp heartbeat cron và storage cleanup;
- deployments `queued`, `running`, `hooks_pending` kéo dài;
- artifact store usage và cảnh báo retention;
- `Project.health_status` cùng thời điểm probe cuối;
- log Laravel khi tác vụ bị lỗi.

Dashboard/System API tổng hợp một số tín hiệu này; chúng không thay thế giám sát host/disk/database bên ngoài.
Giữ log có quyền đọc phù hợp và không ghi Authorization header, `.env` hoặc hook env secrets.

## 3. Nâng cấp Backend an toàn

Một lần upgrade nên theo thứ tự:

1. build artifact mới và kiểm tra checksum;
2. giữ nguyên `shared/.env`, `shared/storage` và database;
3. giải nén vào release directory mới, không ghi đè code hiện tại;
4. chạy migration theo hướng tương thích của release;
5. warm/cache config khi phù hợp;
6. activate release mới, smoke test health/auth/cron;
7. giữ đủ release cũ để rollback code nếu cần.

Rollback code không tự rollback migration/database. Thiết kế migration theo hướng tương thích trước/sau deploy;
backup database trước migration có rủi ro.

## 4. Chẩn đoán sự cố

| Triệu chứng | Kiểm tra trước |
|---|---|
| Deployment `hooks_pending` lâu | Cron cadence, PHP CLI path, worker log, command probe |
| Deployment `queued` lâu | Worker chạy không, project còn deployment active hay lock không |
| Preflight/path error | Allow-list, absolute path, symlink ancestor, user permission |
| Health check fail | URL/status/TLS/timeouts, ứng dụng đích và Document Root |
| Disk tăng liên tục | Housekeeping heartbeat, artifact prune config, release retention |
| Session/API login lỗi | APP_KEY, session DB/table, cookie HTTPS và CSRF |

Chạy lệnh chẩn đoán chỉ sau khi xác nhận app directory và database là production mong muốn:

```bash
php artisan schedule:list
php artisan migrate:status
php artisan cporter:run-jobs --help
```

Không dùng `migrate:fresh` hoặc xóa `shared/` để “sửa nhanh” production.

## Checklist vận hành

- [ ] Cron gọi đúng release hiện tại bằng PHP CLI.
- [ ] Heartbeat mới và queue được drain.
- [ ] Có backup DB trước migration rủi ro.
- [ ] `shared/` và database được giữ qua lần deploy.
- [ ] Rollback plan nêu rõ code rollback không đảo ngược DB migration.

👉 Hoàn tất lộ trình Backend. Tham khảo thêm [API contract](../../docs/API.md),
[triển khai cPanel](../../docs/DEPLOYMENT-CPANEL.md) và [SPEC](../../docs/SPEC.md).
