# FE 13 — Responsive, accessibility và UX states

🎯 **Mục tiêu:** bảo đảm app dùng được trên mobile, bàn phím, light/dark và trạng thái loading/error/empty.

## 1. Responsive layout

`Layout` và `DocsLayout` dùng Mantine `AppShell` với navbar collapse ở breakpoint `sm`; Burger bật menu mobile.
Grid dùng responsive `cols`, control có width theo breakpoint, table đặt trong scroll container khi nội dung rộng.
Ưu tiên Mantine responsive style props thay vì media query global mới.

Khi thêm trang, thử viewport desktop, tablet và phone; không chỉ thu nhỏ browser rồi coi mọi overflow là accept.

## 2. Keyboard và semantics

- Dùng Button/Anchor/NavLink native semantics, không thay action bằng clickable `div`.
- Controls icon-only cần `aria-label`; các form field cần label.
- Dialog có title, close/cancel và focus behavior theo Mantine.
- Table tương tác phải hỗ trợ keyboard như row mở `DeploymentDrawer`.
- Giữ focus indicator; không bỏ outline bằng CSS.

## 3. Trạng thái dữ liệu rõ ràng

| Trạng thái | UI phù hợp |
|---|---|
| Đang tải | Loader/Skeleton có kích thước gần nội dung |
| Lỗi request | Alert, thông tin dễ hiểu, retry khi phù hợp |
| Data rỗng thật | EmptyState nói cách tiếp tục |
| Filter không match | EmptyState riêng; không nhầm với không có record |
| Mutation pending | Button loading/disabled |
| Thành công/thất bại | Notification hoặc field error tương ứng |

`PanelBody` xử lý query loading/error; `EmptyState` xử lý empty. Không trả empty array từ catch để che lỗi.

## 4. Màu và nội dung

Dùng Mantine theme/token để hỗ trợ light/dark. Status color nên kèm label/icon, không chỉ mã màu. Dùng text/button
ngắn, mô tả hậu quả của revoke/delete/adopt và không hiển thị secret trong notification.

## Kiểm tra thủ công

- Tab/Shift+Tab đi qua controls theo thứ tự hợp lý.
- Enter/Space kích hoạt action tương tác.
- Light và dark có contrast tốt.
- Mobile nav và table có thể thao tác, không che nút quan trọng.
- Query failure không bị trình bày như empty success.

👉 Tiếp theo: [14 — Build, kiểm tra và deploy](14-build-deploy.md).
