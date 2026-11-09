# FE 02 — Bootstrap, Mantine và theme

🎯 **Mục tiêu:** hiểu React entrypoint, thứ tự CSS, provider tree và nơi thay đổi token giao diện.

## 1. Entry point và thứ tự CSS

`apps/web/src/main.tsx` import Mantine core trước stylesheet khác:

```tsx
import '@mantine/core/styles.css';
import '@mantine/notifications/styles.css';
import './index.css';
```

Thứ tự quan trọng: styles của Mantine sub-package phải sau core. CSS global của project chỉ dành cho app-level
rules; không thêm framework CSS/reset thứ hai.

## 2. Provider tree hiện tại

Root tạo provider cần thiết cho toàn app:

```text
StrictMode
└─ MantineProvider
   ├─ Notifications
   └─ ModalsProvider
      └─ QueryClientProvider
         └─ BrowserRouter
            └─ AuthProvider
               └─ App
```

`AuthProvider` dùng React Query; routes/page cần Router context. Đặt provider ở `main.tsx` khi dependency thực sự
toàn cục, thay vì tạo instance riêng trong từng page.

## 3. Theme tokens

`apps/web/src/theme.ts` dùng `createTheme()` cho primary color, radius, font và component defaults. Ví dụ trong
theme thực tế cần đọc file thay vì chép một theme rút gọn; khi đổi màu hay radius, sửa token ở một nơi để UI nhất
quán.

Mantine cho phép mở rộng theme object; xem [tài liệu chính thức](https://mantine.dev/llms/theming-theme-object.md).

## 4. Light/dark color scheme

`ColorSchemeScript` và `MantineProvider` dùng `defaultColorScheme="auto"`. Toggle giao diện nằm trong app header.
Dùng theme tokens/style props như `c="dimmed"` thay vì kiểm tra `document.body` hoặc hard-code màu chỉ hợp một
scheme.

## 5. Kiểm tra sau thay đổi

```bash
pnpm --filter @cporter/web build
pnpm --filter @cporter/web lint
```

Mở app ở light và dark, kiểm tra notifications/modals và đảm bảo CSS sub-package không bị import trước core.

👉 Tiếp theo: [03 — Routing, AppShell và navigation](03-routing-shell.md).
