# BE 06 — Path jail và cấu hình an toàn

🎯 **Mục tiêu:** chặn project chạm tới đường dẫn ngoài allow-list, kể cả khi đường dẫn có `..`, prefix trùng
hoặc đi qua symlink. Trên hosting không có `open_basedir`, đây là ranh giới an toàn quan trọng.

## 1. Cấu hình jail

Trong `.env`, khai báo một hoặc nhiều root tuyệt đối, phân cách bằng dấu phẩy:

```dotenv
CPORTER_ALLOWED_BASE_PATHS=/home/account/sites,/home/account/domains
```

`config/cporter.php` chuyển giá trị đó thành danh sách. Nếu danh sách rỗng, app không nên tự cho phép toàn
filesystem; hãy kiểm tra hành vi fail-closed trong `PathJail` trước khi thêm capability mới.

## 2. Chuẩn hóa trước khi kiểm tra

`PathJail::normalize()` từ chối path rỗng, byte NUL và path tương đối; gom `.`/`..` để có dạng chuẩn:

```php
$normalized = $jail->normalize('/srv/sites/demo/../other');
// /srv/sites/other
```

Đây mới là chuẩn hóa cú pháp. Để chống symlink escape, `assertInside()` resolve path thật bằng `realpath`
(kể cả ancestor gần nhất nếu leaf chưa tồn tại), sau đó kiểm tra nằm trong root jail bằng ranh giới thư mục,
không chỉ so chuỗi prefix.

```php
$safeBase = $jail->assertInside($project->base_path);
```

Mọi filesystem write/delete cho project phải đi qua adapter đã gọi jail. Không nhận đường dẫn tuyệt đối tùy
ý từ request để truyền thẳng vào `unlink`, `mkdir` hoặc thao tác tương đương.

## 3. Khởi tạo app và Settings

`PathJail` được bind trong `AppServiceProvider`; config runtime được đọc qua `config()`. Runtime capability
probe được lưu riêng trong bảng `settings`, không trộn với allow-list tĩnh. Khi config env đổi và có config
cache, phải tái tạo cache theo quy trình deploy thay vì kỳ vọng app đọc `.env` mỗi request.

## 4. Test các trường hợp biên

Test tại `apps/api/tests/Feature/PathJailTest.php` dùng thư mục tạm và bao phủ traversal, sibling path có
prefix giống nhau, path chưa tồn tại và symlink. Khi mở rộng jail, thêm test tương ứng:

```bash
php artisan test --filter=PathJailTest
```

Đường dẫn như `/home/account/sites-evil` không thuộc jail `/home/account/sites`. Symlink trong jail trỏ ra
ngoài cũng phải bị từ chối.

## Checklist

- [ ] Chỉ cấu hình root tuyệt đối, tối thiểu cần thiết.
- [ ] Resolve symlink và kiểm tra ranh giới thư mục.
- [ ] Tất cả thao tác filesystem project đi qua adapter.
- [ ] Test traversal, prefix confusion và symlink escape.

👉 Tiếp theo: [07 — Storage adapter và ZIP](07-storage-adapter-zip.md).
