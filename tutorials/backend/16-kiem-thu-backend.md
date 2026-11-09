# BE 16 — Kiểm thử Backend

🎯 **Mục tiêu:** chọn đúng loại test cho contract/API, logic domain, DB và filesystem; thêm regression test
trước khi thay đổi pipeline.

## 1. Pest và cấu hình test

Test nằm trong `apps/api/tests`; cấu hình Pest/Laravel nằm ở `tests/Pest.php`, `phpunit.xml` và base
`tests/TestCase.php`. Chạy lệnh tại `apps/api`:

```bash
php artisan test
```

Nếu dùng Docker:

```bash
docker compose exec api php artisan test
```

## 2. Chọn test phù hợp

- **Unit test:** logic thuần như render `.env`, normalize input.
- **Feature test:** route, middleware, validation, database và response.
- **Adapter test:** filesystem thật trong thư mục tạm, đặc biệt symlink, ZIP, lock và prune.
- **Pipeline test:** ZIP test → API → Deployment → release trên đĩa.

Không dùng filesystem thật của project production. `RefreshDatabase` cô lập database; `sys_get_temp_dir()` và
cleanup `afterEach` cô lập file test.

## 3. Tạo test theo kịch bản

Kịch bản nên khẳng định cả kết quả lẫn side effect không mong muốn:

```php
$response->assertStatus(202)
    ->assertJsonPath('data.status', 'success');

expect(is_link($base.'/current'))->toBeTrue()
    ->and(file_get_contents($base.'/current/index.html'))->toBe($expectedHtml);
```

Với lỗi validation/security, kiểm tra status code và xác nhận không có release/active symlink mới. Với async
queue, xác nhận status queued và gọi dispatcher/worker riêng trong test.

## 4. Chạy test có chọn lọc

```bash
php artisan test --filter=PathJailTest
php artisan test --filter=DeployPipelineTest
php artisan test --filter=LaravelDeployTest
php artisan test --filter=DeployBacklogTest
```

Sau test nhóm nhỏ, chạy suite đầy đủ trước release. Nếu suite thất bại, đọc test name/stack trace trước khi
thay implementation; không tắt test để làm build xanh giả.

## Checklist

- [ ] Test đúng layer (unit, API, adapter, pipeline).
- [ ] Test kiểm cả response và filesystem/DB side effect.
- [ ] Test cleanup thư mục tạm kể cả khi thất bại.
- [ ] Full suite xanh trên PHP version tương thích với production.

👉 Tiếp theo: [17 — Đóng gói và cài lần đầu lên cPanel](17-build-cai-dat-cpanel.md).
