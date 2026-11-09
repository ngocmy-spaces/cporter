# FE 11 — Deployments và timeline

🎯 **Mục tiêu:** hiển thị feed deploy có filter và chi tiết tiến trình phù hợp với worker chạy nền trên cPanel.

## 1. Deployment feed

`DeploymentsPage` gọi `GET /deployments`, poll mỗi 10 giây, rồi lọc client-side theo project/status. Status groups:

- `queued`, `running`, `hooks_pending`: in-flight;
- `success`: thành công;
- `failed`, `rolled_back`: failure.

Trang hiển thị 50 deployment gần nhất theo API contract, stats trên feed đang có, project selector và empty state
phân biệt feed rỗng với filter không khớp. Nếu backend đổi page limit/contract, cập nhật mô tả và logic cùng lúc.

## 2. Mở drawer detail

Chọn một row lưu `deploymentId` UI state và mở `DeploymentDrawer`. Drawer query key `['deployments', id]`, gọi
`GET /deployments/{id}`; query chỉ chạy khi ID khác null.

`DeploymentDrawer` dùng timeline của các bước backend đã ghi. Khi deployment in-flight, poll 2 giây; dừng khi
terminal. Vì backend chỉ ghi completed steps, component suy ra stage hiện tại từ bước đã hoàn tất và config
project. UI inference là estimate, không phải state machine authoritative.

## 3. Render step timeline

Mỗi step có status success/failed/warning, thời lượng và output. Hook step name có dạng động; UI humanize tên
stage nhưng giữ command/output theo policy hiện có. Error state drawer có retry/close; loading state dùng loader.

Không render raw deployment logs bằng HTML và không giả định output an toàn để chèn qua `dangerouslySetInnerHTML`.

## 4. Trigger, retry và rollback

Các action retry/rollback chỉ gửi request nếu API contract cho phép và user có quyền. Confirm action phá hủy hoặc
có downtime. Sau action success, invalidate feed/detail liên quan để status mới không chờ tới lần poll tiếp.
Backend là nguồn xác định kết quả; UI không tự đổi status thành success.

## 5. Bảng và keyboard

Table row mở drawer hỗ trợ Enter/Space, `tabIndex`, `role="button"` và label accessible. Nếu sửa markup, duy trì
keyboard interaction; không chỉ dựa vào click chuột.

## Kiểm tra

- In-flight deployment cập nhật trong drawer.
- Polling dừng khi `success`, `failed` hoặc `rolled_back`.
- API error không hiển thị như feed trống.
- Row mở được bằng chuột và bàn phím.

👉 Tiếp theo: [12 — Admin và public docs](12-admin-public-docs.md).
