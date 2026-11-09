# BE 05 — API key, scope và capability

🎯 **Mục tiêu:** xác thực CI không dùng session browser: cấp token ngẫu nhiên một lần, chỉ lưu hash và giới hạn
quyền bằng scope/project binding.

## 1. Token và hash

`ApiKeyService` sinh token có prefix `cpk_`; database giữ hash SHA-256 và prefix tra cứu, không giữ plaintext.
Mẫu tạo token an toàn:

```php
$secret = bin2hex(random_bytes(24));
$token = 'cpk_'.$secret;
$hash = hash('sha256', $token);
```

Plaintext chỉ trả về lúc tạo API key để người dùng lưu vào secret store của CI. Nếu mất token thì revoke và
tạo token mới; không thể xem lại token cũ từ hash.

## 2. Authentication rồi authorization

Middleware `apikey` gọi `ApiKeyService::authenticate()` và lưu key đã xác thực vào request attributes.
Middleware `scope:<name>` tiếp theo mới kiểm quyền:

```php
Route::middleware(['apikey', 'scope:deploy'])->group(function () {
    Route::post('/projects/{project}/deployments', [DeploymentController::class, 'store']);
});
```

Đây là mẫu; route chính thức trong `apps/api/routes/api.php` là nguồn chuẩn cho mỗi endpoint. Scope và cách
`admin` được coi là quyền bao trùm nằm trong `apps/api/app/Models/ApiKey.php` và
`apps/api/app/Enums/ApiScope.php`.

## 3. Giới hạn key theo project

Sau khi scope được chấp nhận, controller/service kiểm tra `project_id` gắn trên key. Key dùng toàn hệ thống
có thể không gắn project; key scoped chỉ được dùng với project tương ứng. Hai check độc lập:

1. key có scope phù hợp;
2. key được phép truy cập project đang yêu cầu.

Trả `403` nếu key hợp lệ nhưng thiếu quyền/phạm vi; `401` nếu token thiếu hoặc sai.

## 4. Capability probe

Deploy phụ thuộc vào khả năng host như ZipArchive, symlink, dung lượng và binary shell. Probe không thay thế
guard runtime: capability có thể đổi giữa lúc probe và deploy, nên adapter vẫn phải xử lý lỗi thực tế.
Đọc `apps/api/app/Domain/System/CapabilityProbe.php` và `SystemController.php`.

## 5. Test và vận hành token

```bash
php artisan test --filter=ApiKeyAuthTest
php artisan test --filter=CapabilitiesTest
```

Thêm test các trường hợp: token sai, hết hạn/revoke, scope không đủ và key bị bind sang project khác. Không
log `Authorization` header, plaintext token hoặc nội dung secrets.

## Checklist

- [ ] Token ngẫu nhiên, plaintext hiển thị một lần, DB giữ hash.
- [ ] Phân biệt `401` với `403`.
- [ ] Kiểm cả scope lẫn project binding.
- [ ] Probe host và runtime guard có vai trò riêng.

👉 Tiếp theo: [06 — Path jail và cấu hình an toàn](06-path-jail-settings.md).
