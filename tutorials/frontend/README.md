# Frontend — Hướng dẫn xây dựng cPorter Admin SPA

Bộ tutorial này hướng dẫn **Frontend trong `apps/web`**: React 19 + TypeScript + Vite + Mantine v9 +
React Router 7 + TanStack Query 5. Bài học đi từ cấu trúc repo đến các trang đang chạy, rồi build FE vào
Laravel public directory để triển khai.

Phần này tập trung FE. API Laravel được xem như backend có sẵn; tham khảo
[docs/API.md](../../docs/API.md) cho hợp đồng HTTP và [docs/FRONTEND.md](../../docs/FRONTEND.md) cho conventions.

## Lộ trình

| Bài | Chủ đề | Kết quả |
|---|---|---|
| [00 — Tổng quan Frontend](00-tong-quan.md) | Kiến trúc SPA, luồng dữ liệu, route/page | Vẽ được đường đi từ route tới API |
| [01 — Setup môi trường FE](01-setup-local.md) | pnpm, Vite, API proxy, kết nối local API | Chạy ứng dụng ở `localhost:5173` |
| [02 — Bootstrap, Mantine và theme](02-bootstrap-theme.md) | entrypoint, CSS order, provider, theme tokens | Tùy chỉnh theme Mantine |
| [03 — Routing, AppShell và navigation](03-routing-shell.md) | public/admin routes, Layout, responsive nav | Thêm route và nav đúng khu vực |
| [04 — API client và TypeScript types](04-api-client-types.md) | Axios, response envelope, domain types | Gọi API với types phù hợp |
| [05 — Session auth và Login](05-session-auth-login.md) | cookie, CSRF/XSRF, AuthProvider, RequireAuth | Đăng nhập theo session |
| [06 — React Query và trạng thái API](06-react-query-state.md) | query keys, polling, mutations, invalidation | Đồng bộ UI với API |
| [07 — Mantine forms và feedback](07-forms-feedback.md) | useForm, API 422, notifications, shared states | Form có validation và lỗi rõ ràng |
| [08 — Dashboard](08-dashboard.md) | stats, alerts, deployments feed | Tạo overview từ API thật |
| [09 — Projects](09-projects.md) | danh sách, search, pagination, create | Quản lý project từ UI |
| [10 — Project detail và Environment](10-project-detail-environment.md) | tabs, releases/activity, hooks, secrets | Hoàn thiện màn hình chi tiết |
| [11 — Deployments và timeline](11-deployments-timeline.md) | filters, polling, DeploymentDrawer | Theo dõi deploy đang chạy |
| [12 — Admin và public docs](12-admin-public-docs.md) | API keys, users, system, audit, docs routes | Hoàn thiện bề mặt quản trị |
| [13 — Responsive, accessibility và UX states](13-responsive-accessibility.md) | mobile, loading/error/empty, keyboard | UI hoạt động rõ ràng ở nhiều kích cỡ |
| [14 — Build, kiểm tra và deploy](14-build-deploy.md) | typecheck, lint, Vite build, Laravel artifact | Đóng gói FE để phát hành |

## Nguyên tắc

- **Mantine v9 là UI kit duy nhất:** không thêm Tailwind, Bootstrap, MUI hay CSS framework khác.
- **Xác minh API Mantine:** dùng [index chính thức](https://mantine.dev/llms.txt) và docs chuyên đề thay vì
  đoán component/props.
- **Server state thuộc React Query:** query key phải phản ánh đầy đủ tham số; mutation cần đồng bộ cache.
- **Browser dùng session cookie + CSRF**, không dùng bearer API key. API key là credential cho CLI/CI.
- **Không biến lỗi thành dữ liệu rỗng:** phân biệt loading, error, empty và success.
- **TypeScript strict:** thêm domain types trong `src/lib/types.ts`, tránh `any`.
- **Hỗ trợ light/dark:** ưu tiên Mantine tokens/style props và kiểm tra cả hai scheme.

## Lệnh kiểm tra

Từ repository root:

```bash
pnpm --filter @cporter/web typecheck
pnpm --filter @cporter/web lint
pnpm --filter @cporter/web build
```

Hiện repo chưa cấu hình bộ unit/component test riêng cho FE. Bài 14 ghi các bước kiểm tra thủ công và smoke test.

## Bắt đầu

Đọc [00 — Tổng quan Frontend](00-tong-quan.md), sau đó làm theo
[01 — Setup môi trường FE](01-setup-local.md).
