# BE 04 — Session, đăng nhập và vai trò

🎯 **Mục tiêu:** xây luồng đăng nhập cho admin panel bằng cookie session, CSRF và role; không nhầm cơ chế này
với API key dành cho CI (bài 05).

## 1. Hai lớp quyền

- **Authentication:** request đang đại diện cho user nào? Laravel `auth` session trả lời câu hỏi này.
- **Authorization:** user đó được phép thực hiện hành động gì? `EnsureRole` chặn hành động admin-only.

Trong app hiện tại admin và viewer có thể đọc, còn route ghi được đặt trong middleware `role:admin`.
Đừng mặc định mọi `POST` là an toàn: quyền phải được xác định ở route.

## 2. Luồng browser

SPA gọi `GET /api/v1/csrf` để nhận cookie CSRF, gửi `POST /api/v1/auth/login`, rồi dùng session cookie cho
các request tiếp theo. Laravel middleware `web` xử lý session/CSRF; endpoint `auth/user` trả user hiện tại.

Route hiện hành nằm trong `apps/api/routes/api.php`; controller tại
`apps/api/app/Http/Controllers/Api/AuthController.php`. Đọc hai file đó trước khi sửa để thấy thứ tự middleware.

## 3. Chặn thao tác theo role

Middleware hiện có có dạng:

```php
if ($request->user()?->role !== $role) {
    return response()->json(['error' => "This action requires the '{$role}' role."], 403);
}

return $next($request);
```

Đăng ký alias ở bootstrap rồi gắn nó sau `auth`:

```php
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::post('/projects', [ProjectController::class, 'store']);
});
```

Thứ tự quan trọng: xác thực user trước, kiểm role sau. Phần alias/bootstrap thực tế nằm trong
`apps/api/bootstrap/app.php`.

## 4. Tránh lộ thông tin user

Chỉ trả những trường cần thiết. Các route đọc/ghi user được quản lý bởi
`apps/api/app/Http/Controllers/Api/UserController.php`; test role/self-delete trong
`apps/api/tests/Feature/Phase3Test.php`.

Một route ghi mới phải có cả test:

```php
$this->actingAs($viewer)
    ->postJson('/api/v1/projects', $payload)
    ->assertForbidden();
```

## 5. Test luồng auth

Đọc `apps/api/tests/Feature/AuthTest.php`, sau đó kiểm tra nhóm route bằng:

```bash
php artisan route:list --path=api/v1/auth
php artisan test --filter=AuthTest
php artisan test --filter='viewer read but not write'
```

Kỳ vọng:

- request chưa đăng nhập nhận `401` dạng JSON trên API;
- viewer đọc được endpoint được cho phép;
- viewer bị `403` khi gọi thao tác admin;
- request ghi từ browser hợp lệ cần CSRF.

## Checklist

- [ ] Phân biệt session của browser với API key của CI.
- [ ] Biết xem và đặt middleware đúng thứ tự.
- [ ] Có test cả unauthenticated, admin và viewer.
- [ ] Không đưa secrets hoặc dữ liệu nhạy cảm vào payload user chung.

👉 Tiếp theo: [05 — API key, scope và capability](05-api-key-scope-capability.md).
