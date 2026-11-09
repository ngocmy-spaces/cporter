# FE 04 — API client và TypeScript types

🎯 **Mục tiêu:** gửi request thống nhất qua Axios instance, khớp response Laravel và chia sẻ domain types.

## 1. Shared Axios client

`apps/web/src/lib/api.ts` tạo Axios instance với base URL mặc định `/api/v1` (có thể override bằng
`VITE_API_BASE_URL`), `withCredentials`, JSON `Accept` và cấu hình XSRF cookie/header. Production là same-origin
Laravel; Vite proxy xử lý local. Page import `api`, không gọi `axios.get()` riêng, để giữ base URL/auth/CSRF
nhất quán.

```tsx
const response = await api.get<ApiEnvelope<Project[]>>('/projects');
const projects = response.data.data;
```

Base URL đã chứa `/api/v1`, vì vậy endpoint chỉ truyền `/projects`.

## 2. Response envelope

Laravel API thường trả `{ data: ... }`, có endpoint kèm `meta`, `probed_at` hoặc token hiển thị một lần. Kiểm tra
`src/lib/types.ts` và [API contract](../../docs/API.md), không giả định mọi endpoint dùng cùng một shape.

## 3. Domain types

Interfaces và unions như `Project`, `Deployment`, `Release`, `ApiKey`, `User`, `AuditLog` nằm trong
`src/lib/types.ts`. Ví dụ:

```ts
export type DeploymentStatus =
  | 'queued'
  | 'running'
  | 'hooks_pending'
  | 'success'
  | 'failed'
  | 'rolled_back';
```

Trước khi thêm field, xác minh response Laravel serializer thực sự trả field đó. Giữ type đồng bộ với giá trị enum
API, không tự biến string tùy ý thành domain status.

## 4. Cache shape là hợp đồng

Các component cùng query key phải dùng cùng response shape. Nếu một query cache `{ data: Capabilities }` nhưng
consumer khác lại đọc như `Capabilities`, runtime sẽ sai dù hai nơi typecheck riêng.

Với response phức tạp, khai báo interface chung trong `types.ts` hoặc feature API module. Query key phải chứa mọi
tham số làm thay đổi response.

## 5. Xử lý lỗi

Catch error dưới dạng `unknown`; dùng `axios.isAxiosError` hoặc helper `extractApiError`. Không ép `as any`,
không lưu secret trong query key và không log request config chứa cookie/credential.

## Kiểm tra

```bash
pnpm --filter @cporter/web typecheck
```

TypeScript strict sẽ bắt nullability, field thiếu và response type không khớp.

👉 Tiếp theo: [05 — Session auth và Login](05-session-auth-login.md).
