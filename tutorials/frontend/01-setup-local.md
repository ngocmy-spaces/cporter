# FE 01 — Setup môi trường Frontend local

🎯 **Mục tiêu:** cài dependency workspace, khởi chạy Vite và nối browser với Laravel API local.

## 1. Yêu cầu

- Node 20+
- pnpm 11+ (repo khai báo package manager ở root)
- Backend local đang chạy, hoặc Docker Compose stack đã khởi động

FE có thể mở khi API chưa chạy, nhưng login và dữ liệu thật cần Laravel. Với Docker, tham khảo
[tutorial Backend 01](../backend/01-setup-local.md).

## 2. Cài dependencies và chạy Vite

Từ repository root:

```bash
corepack enable
pnpm install
pnpm --filter @cporter/web dev
```

Mở `http://localhost:5173`. Script `dev` chạy Vite trong package FE.

## 3. API proxy

`apps/web/vite.config.ts` proxy prefix `/api` tới API Laravel, mặc định `http://localhost:8000`; target có thể
được override bằng `VITE_API_PROXY_TARGET`. FE gọi cùng-origin `/api/v1/...`, Vite chuyển tiếp request trong
dev. Cách này tránh hard-code host API trong browser và giữ session/CSRF cookie đi qua cùng origin Vite.

Nếu Laravel chạy trong Docker, proxy target phải là host/port nhìn thấy từ tiến trình Vite. Hostname nội bộ Docker
thường không resolve từ browser bên ngoài.

## 4. Kiểm tra kết nối

1. Kiểm tra API health tại `http://localhost:8000/api/v1/health`.
2. Mở DevTools → Network và xác nhận request `/api/v1/...` được Vite chuyển tiếp.
3. Nếu login lỗi, kiểm tra API target, DB migration/seed, cookie XSRF và Laravel session config.

Không đặt API key hoặc secret trong biến `VITE_*`: Vite nhúng giá trị đó vào bundle public.

## 5. Lệnh chất lượng

```bash
pnpm --filter @cporter/web typecheck
pnpm --filter @cporter/web lint
pnpm --filter @cporter/web build
```

## Checklist

- [ ] Vite chạy tại `localhost:5173`.
- [ ] API health trả JSON.
- [ ] Request API dùng proxy, không gọi nhầm host.
- [ ] Không có secrets trong config FE.

👉 Tiếp theo: [02 — Bootstrap, Mantine và theme](02-bootstrap-theme.md).
