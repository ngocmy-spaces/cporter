# FE 03 — Routing, AppShell và navigation

🎯 **Mục tiêu:** phân chia public/admin routes, bảo vệ admin app bằng auth gate và thêm trang vào responsive nav.

## 1. Public và protected route

`App.tsx` đặt `/docs/*` bên ngoài `RequireAuth`; dashboard và các trang quản trị nằm bên trong auth gate. Gate
hiển thị loader khi bootstrap auth chưa xong, render `LoginPage` nếu chưa có user, hoặc `<Outlet />` khi đã đăng
nhập.

Không đưa docs public vào protected branch. Người chưa đăng nhập vẫn phải đọc docs.

## 2. Thêm route cho feature

Đặt route mới trong `apps/web/src/App.tsx`, dưới auth/layout nếu cần đăng nhập:

```tsx
<Route element={<RequireAuth />}>
  <Route element={<Layout />}>
    <Route path="projects" element={<ProjectsPage />} />
    <Route path="projects/:slug" element={<ProjectDetailPage />} />
  </Route>
</Route>
```

Đây là cấu trúc minh họa theo route tree hiện tại. Dùng `useParams` cho dynamic segment, không tự cắt chuỗi
pathname.

## 3. AppShell navigation

`components/Layout.tsx` dùng Mantine `AppShell` cho header/navbar/main. Nav data tách thành main/admin groups;
`adminOnly` giúp ẩn phần admin khỏi viewer, còn breakpoint/collapsed state điều khiển mobile navigation.
Tham khảo [AppShell docs](https://mantine.dev/llms/core-app-shell.md) trước khi thêm props mới.

Khi thêm nav item:

1. thêm route;
2. thêm item vào đúng nav group;
3. quyết định item có `adminOnly` không;
4. kiểm tra route con vẫn hiển thị active;
5. kiểm tra mobile nav đóng sau khi điều hướng.

Ẩn UI theo role không thay thế authorization phía Laravel.

## 4. SPA navigation

Internal links dùng React Router `Link`/`NavLink`, để không reload toàn trang. External URL dùng anchor bình thường.
Tránh hard-code `<a href="/projects">` cho route nội bộ.

## Kiểm tra

- `/docs/overview` mở được khi chưa login.
- `/projects` bị chặn khi chưa login.
- Nav active khi mở route detail.
- Menu mobile mở, đóng và điều hướng được bằng bàn phím.

👉 Tiếp theo: [04 — API client và TypeScript types](04-api-client-types.md).
