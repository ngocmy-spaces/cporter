# BE 10 — Deploy pipeline

🎯 **Mục tiêu:** theo một Deployment từ lúc nhận API đến lúc code mới sống trong `current`; nhận diện từng
failure boundary để trạng thái DB, filesystem và lock không mâu thuẫn.

## 1. Dispatch và job

`DeploymentController` lưu bản ghi rồi gọi `DeployDispatcher`; `DeployProjectJob` chuyển orchestration sang
`DeployEngine`. Queue driver `sync` giúp test chạy inline, còn môi trường production dùng database queue và
worker do scheduler/cron kích hoạt.

Job nên chứa identity của deployment, không nhận path tùy ý từ request. Engine resolve project/release/artifact
từ database và guard terminal status để job gửi lại không chạy pipeline lần hai.

## 2. Các bước pipeline

Luồng không có hooks hiện tại theo thứ tự lớn:

1. acquire lock theo project;
2. extract ZIP vào release mới;
3. render env và link shared paths;
4. validate docroot/entrypoint;
5. activate `current`;
6. health check;
7. prune release cũ và ghi thống kê.

Mỗi bước đi qua `StepRunner`, lưu trạng thái/thời gian/lỗi vào `deployments.steps`. UI/CI poll deployment để
đọc timeline; vì vậy step name và error message là dữ liệu vận hành, không chỉ là log tạm.

## 3. Lock và cleanup

Lock filesystem ngăn cùng project deploy song song. Mọi đường thoát sau khi lấy lock phải nhả nó, trừ luồng
hook đã chuyển lock ownership sang cron worker. Dùng `try/finally`; không bắt lỗi rồi đánh dấu success.

```php
$locked = false;
try {
    $this->storage->acquireLock($project->base_path);
    $locked = true;
    // pipeline stages
} finally {
    if ($locked) {
        $this->storage->releaseLock($project->base_path);
    }
}
```

Snippet chỉ minh họa resource lifecycle; implementation cần phân biệt lock thất bại và lock được handoff.

## 4. Stage và activation

Project có hooks được stage đến trạng thái `hooks_pending`; code chưa chuyển live. Worker cron chạy pre-hooks,
activate, post-hooks rồi health-check. Cách này tránh chạy shell từ web PHP.

## 5. Test end-to-end

`apps/api/tests/Feature/DeployPipelineTest.php` dựng ZIP thật và base directory tạm. Chạy:

```bash
php artisan test --filter=DeployPipelineTest
```

Kiểm tra status terminal, `steps[]`, nội dung qua `current`, release cũ được giữ theo retention và lock được
giải phóng khi validation/hook lỗi.

👉 Tiếp theo: [11 — Health check và rollback](11-health-check-rollback.md).
