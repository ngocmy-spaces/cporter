# BE 08 — Project API

🎯 **Mục tiêu:** nhận cấu hình project từ admin, validate dữ liệu và filesystem trước khi lưu, rồi hiển thị
preflight để operator biết cần chuẩn bị gì.

## 1. Route và controller

Admin routes định nghĩa ở `apps/api/routes/api.php`; controller là
`apps/api/app/Http/Controllers/Api/ProjectController.php`. Route cần đi qua session auth; thao tác thay đổi
cấu hình nằm trong admin role group. Controller nên validate request, gọi Domain/adapter và trả JSON, không
nhét toàn bộ deploy engine vào controller.

## 2. Validate đầu vào

Ví dụ create project cần tên, path và type:

```php
$data = $request->validate([
    'name' => ['required', 'string', 'max:255'],
    'base_path' => ['required', 'string', 'max:1024'],
    'type' => ['required', Rule::enum(ProjectType::class)],
]);
```

Sau validation cú pháp, `PathJail` xác thực quyền truy cập path và controller kiểm tra không có project live
khác dùng cùng base path. Validation request không thay thế những invariant ở tầng nghiệp vụ/filesystem.

## 3. Scaffold và preflight

Khi tạo project, backend persist cấu hình rồi gọi adapter `preflight(base_path, shared_paths)`. Response trả
cả project lẫn báo cáo check: directory đã tạo, symlink capability, shared file cần seed và việc operator
cần trỏ Document Root. Preflight có chủ đích lenient: lỗi chuẩn bị cần được trình bày, không được che giấu.

Các điểm cần đọc trong controller:

- `store()` và `persistProject()` — create dùng chung đường xử lý;
- `update()` — thay đổi config và retention;
- `clone()` — copy config, không copy releases;
- `preflight()` — chạy lại kiểm tra;
- `destroy()` — soft delete hoặc enqueue purge.

## 4. Test API

Xem `apps/api/tests/Feature/ProjectApiTest.php`, tạo base path tạm trong jail và xác nhận:

```bash
php artisan test --filter=ProjectApiTest
```

Test các input ngoài jail, slug trùng, base path đang dùng và quyền viewer. Không test filesystem bằng path
production/dev cố định.

## Checklist

- [ ] Route chỉ cho admin sửa cấu hình.
- [ ] Validate cú pháp và invariant riêng biệt.
- [ ] Base path qua PathJail trước mọi thao tác filesystem.
- [ ] Preflight có kết quả rõ ràng và không cấu hình vhost thay operator.

👉 Tiếp theo: [09 — Deploy API và upload](09-deploy-api-upload.md).
