# BE 09 — Deploy API và upload

🎯 **Mục tiêu:** đưa một artifact từ request CI thành các bản ghi Artifact, Release và Deployment; xác minh
integrity trước khi dispatch pipeline.

## 1. Hợp đồng endpoint

Đối chiếu [docs/API.md](../../docs/API.md). Luồng single request dùng:

```http
POST /api/v1/projects/{slug}/deployments
Authorization: Bearer <API-key>
Content-Type: multipart/form-data
```

Body có `artifact`, `sha256`, `version` tùy chọn. Route được bảo vệ bởi `apikey` và `scope:deploy`; controller
kiểm tra project binding và trạng thái project trước khi nhận file.

## 2. Kiểm tra hash và ghi record

Sau khi adapter lưu artifact, server tính lại digest:

```php
$actualSha = hash_file('sha256', $storagePath);
if ($actualSha === false || ! hash_equals(strtolower($expectedSha), strtolower($actualSha))) {
    @unlink($storagePath);
    return response()->json(['error' => 'Artifact hash mismatch.'], 422);
}
```

So sánh constant-time (`hash_equals`) và fail closed. Sau đó tạo artifact verified, release pending và
deployment queued; dispatch qua `DeployDispatcher`. Dùng transaction nếu mở rộng luồng tạo record để giữ
quan hệ nhất quán khi lỗi giữa chừng.

## 3. Idempotency

Header `Idempotency-Key` dùng để replay request an toàn: cùng project + key trả deployment đã tạo thay vì
tạo một release mới. Unique constraint trong DB là backstop cho hai request đồng thời; kiểm tra controller
và migration khi sửa semantics hoặc cách trả lỗi.

## 4. Upload chia chunk

Contract hiện tại gồm init → put chunks dạng raw body → complete. Khi complete, server ghép file, đưa file
qua adapter, kiểm hash rồi mới tạo deploy. Dọn thư mục session sau complete thành công hoặc sau lỗi ghép/
lưu; housekeeping xử lý session bỏ dở quá hạn.

> Giới hạn cấu hình artifact phải được áp dụng xuyên suốt cả upload đơn và upload chunk. Khi thay đổi upload
> service, kiểm tra tổng dung lượng trước khi ghép để tránh tiêu thụ tài nguyên không giới hạn.

## 5. Kiểm tra

```bash
php artisan test --filter=DeployPipelineTest
php artisan test --filter=ChunkedUploadTest
```

Bao phủ hash mismatch, thiếu scope, project key sai phạm vi, replay idempotency và file ZIP hợp lệ. Đọc
`DeploymentController` để xác nhận status code và response shape thực tế.

👉 Tiếp theo: [10 — Deploy pipeline](10-deploy-pipeline.md).
