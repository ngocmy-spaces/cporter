# FE 12 — Admin và public docs

🎯 **Mục tiêu:** hiểu các trang quản trị phụ, data permissions, audit feed và tài liệu public không cần đăng nhập.

## 1. API Keys

`ApiKeysPage` tải API keys/projects, tạo key bằng name, scopes, optional project và expiry; revoke yêu cầu confirm.
API trả plaintext token một lần khi tạo mới; UI chỉ giữ token ở local component state để copy/hiển thị ngay và không
thể đọc lại từ danh sách sau này. Không đưa token vào query cache, URL, console hoặc analytics.

Scopes và project constraint phải khớp API contract. Viewer/role UI chỉ là UX; backend phải enforce capability.

## 2. Users

`UsersPage` chỉ chạy query khi user hiện tại có role admin và render access-denied UI cho viewer. Form có name,
email, password, role; xóa user cần confirmation. Sau mutation invalidate `['users']`.

Ẩn menu hoặc query `enabled: false` không bảo vệ endpoint: API phải trả 403 cho user không đủ quyền.

## 3. System và Activity

`SettingsPage` đọc capabilities, cron và storage; cron/storage poll mỗi 30 giây. Re-probe là admin action, cập
nhật capabilities cache khi thành công.

`LogsPage` tải audit log và poll mỗi 15 giây, filter action ở client. Meta được stringify/hiển thị dưới dạng text
hoặc code; không render HTML từ API. Không hiển thị secret trong audit details.

## 4. Public documentation area

`App.tsx` đặt `/docs/*` ngoài auth gate; `DocsLayout` có AppShell riêng với nav:

- overview, cPanel setup, quickstart CLI;
- GitHub Action, artifact, environment variables;
- MCP, API reference.

Route index redirect tới `/docs/overview`; unknown docs path redirect về overview. Thêm tài liệu gồm route, page
component, docs nav item và link qua lại. Chỉ đưa nội dung có thể công khai vào docs; không render config/secret
instance-specific.

## 5. Xác minh role end-to-end

Test bằng viewer và admin account riêng:

1. kiểm tra navigation/actions trong FE;
2. gửi request trực tiếp với session viewer tới endpoint mutation;
3. xác nhận API trả status authorization phù hợp;
4. đảm bảo role data và audit trail không thể bị thay đổi qua request trái phép.

FE gating không thay thế server authorization. Đặc biệt, trước khi mô tả endpoint là admin-only, đối chiếu route
middleware/backend policy thực tế. Một ví dụ cần lưu ý trong implementation hiện tại là
`POST /projects/{slug}/disk-usage/recompute`: route thuộc auth group nhưng chưa nằm trong `role:admin` group.

## Kiểm tra

- Token API key chỉ hiện sau create, không cache/persist.
- Viewer không thấy controls admin nhưng API cũng từ chối mutation unauthorized.
- Public docs mở được khi chưa login.
- Nội dung API audit được render như text.

👉 Tiếp theo: [13 — Responsive, accessibility và UX states](13-responsive-accessibility.md).
