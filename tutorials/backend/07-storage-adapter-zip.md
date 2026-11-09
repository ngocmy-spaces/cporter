# BE 07 — Storage adapter và ZIP

🎯 **Mục tiêu:** cô lập filesystem sau interface `StorageAdapter`; hiểu luồng lưu artifact, giải nén ZIP an toàn,
tạo thư mục shared và kích hoạt release.

## 1. Interface trước implementation

`apps/api/app/Adapters/Storage/StorageAdapter.php` mô tả các thao tác deploy mà Domain cần: artifact, ZIP,
shared files, release retention, lock, `current`, preflight và thống kê dung lượng. Engine không cần biết
lệnh POSIX hay cách một hosting cụ thể dựng symlink.

`AppServiceProvider` bind interface sang `CpanelFilesystemAdapter`. Khi test cần thay thế adapter, bind fake
hoặc dùng implementation thật trên thư mục tạm — pattern đang dùng trong feature tests.

## 2. Lưu artifact và bảo vệ dung lượng

`putArtifact(sourcePath, projectSlug)` kiểm tra nguồn và giới hạn bytes, tạo thư mục artifact theo slug an toàn
trong storage riêng của cPorter, rồi trả path đã lưu. Artifact store nội bộ khác với project paths trong jail.
SHA-256 được kiểm tra ở API sau khi file được lưu.

Mẫu gọi từ tầng application:

```php
$path = $storage->putArtifact($uploadedFile->getRealPath(), $project->slug);
$actualSha = hash_file('sha256', $path);
```

Nếu hash không khớp expected hash, xóa file tạm và không tạo deployment thành công.

## 3. Giải nén ZIP

`extractZip()` phải bảo vệ ít nhất:

- số lượng entry và tổng bytes sau giải nén;
- đường dẫn entry tuyệt đối hoặc có `..` (Zip-Slip);
- việc tạo output chỉ bên trong release directory đã qua jail.

Không dùng shell `unzip` trong web PHP. cPanel target dựa trên ext-zip/`ZipArchive`; lỗi mở archive hoặc ghi
file phải làm step deploy thất bại, không trả response success giả.

## 4. Shared data và release activation

Thư mục project có dạng:

```text
<base_path>/
├── releases/<release-id>/   # code từng phiên bản
├── shared/                  # dữ liệu sống qua deploy
└── current -> releases/...  # bản đang phục vụ
```

`linkShared()` seed dữ liệu ban đầu nếu phù hợp rồi liên kết shared path vào release. `activate(target, link)`
đổi link qua thao tác tạm + rename khi host cho phép, với fallback được adapter kiểm soát. Không xóa release
đang được `current` trỏ tới khi prune.

## 5. Lock và preflight

`acquireLock()` ngăn hai pipeline chạm đồng thời cùng project; `releaseLock()` chạy trong nhánh cleanup/finally.
`preflight()` chuẩn bị `base_path`, `releases/`, `shared/` và báo các bước yêu cầu thao tác cPanel thủ công;
nó không tự cấu hình Document Root.

## Kiểm tra

```bash
php artisan test --filter=CpanelFilesystemAdapterTest
php artisan test --filter=CpanelFilesystemReleaseTest
```

Test cần dùng temp directory; xác nhận Zip-Slip bị từ chối, `current` chuyển đúng release và active release
được giữ lại sau prune.

👉 Tiếp theo: [08 — Project API](08-project-api.md).
