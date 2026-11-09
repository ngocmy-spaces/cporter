# FE 10 — Project detail và Environment

🎯 **Mục tiêu:** tổ chức thông tin project thành tabs và thực hiện config actions có kiểm soát.

## 1. Nạp dữ liệu theo slug

`ProjectDetailPage` lấy `slug` bằng `useParams` rồi truy vấn project, deployment list, releases, activity và
capabilities. Query keys có slug để chuyển project không dùng nhầm cache. Mỗi panel có thể tải riêng; dùng
`PanelBody` để lỗi panel không bị giả làm dữ liệu rỗng.

Endpoints đang dùng gồm:

- `GET /projects/{slug}`
- `GET /projects/{slug}/deployments`
- `GET /projects/{slug}/releases`
- `GET /projects/{slug}/activity`
- `GET /system/capabilities`

## 2. Tabs và hành động

Trang có overview/configuration, deployment/release, activity và environment panel. Config edit tạo payload rồi
`PATCH /projects/{slug}`; preflight POST; clone POST; status update PATCH; activate POST `/releases/{id}/activate`.
Xóa project yêu cầu confirm và hiển thị hậu quả.

Mutation thành công phải cập nhật/invalidate project/list/deployments/releases liên quan. Action thất bại phải
giữ nguyên state và hiển thị lỗi.

## 3. Environment variables

`ProjectEnvPanel` chỉ tải khi tab active. GET `/projects/{slug}/env` trả bản đã mask theo API policy; editor
PUT gửi key/value list, POST `/env/adopt` yêu cầu nhận quyền quản lý file `.env` hiện có. Hỗ trợ nhập nội dung
hoặc file text qua `parseEnvText`.

Giá trị env được lưu mã hóa server-side và được áp dụng trong deploy kế tiếp. Khi xác nhận adopt, UI cảnh báo rõ
file `shared/.env` có thể bị ghi đè. Không log, render nhầm vào activity hoặc hiển thị secret thô sau response.

## 4. Role và authorization

UI có thể ẩn điều khiển env/edit theo role để tránh action nhầm, nhưng tất cả endpoint mutation cần enforce
permission server-side. Không dùng button visibility làm chứng cứ endpoint bị bảo vệ; xác nhận behavior trong
API contract/route middleware. Có một ngoại lệ cần rà soát trước khi tuyên bố UI action admin-only:
`POST /projects/{slug}/disk-usage/recompute` hiện nằm trong nhóm auth nhưng ngoài middleware `role:admin`.
Ẩn nút phía FE không sửa được ranh giới quyền này.

## 5. Khi thêm tab mới

1. nêu rõ dữ liệu nguồn và query key;
2. chỉ chạy query khi tab cần dữ liệu nếu fetch tốn kém;
3. phân biệt empty/error/loading;
4. invalidation sau mutation phải cập nhật detail lẫn list;
5. kiểm tra deep link `/projects/:slug` và slug không tồn tại.

## Kiểm tra

- Chuyển slug không hiển thị dữ liệu project trước.
- API 422 được map đúng nested field.
- Adopt cảnh báo overwrite; secret không lộ ngoài editor.
- Mọi mutation vẫn bị backend authorization kiểm soát.

👉 Tiếp theo: [11 — Deployments và timeline](11-deployments-timeline.md).
