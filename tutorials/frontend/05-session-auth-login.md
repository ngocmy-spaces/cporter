# FE 05 — Session auth và Login

🎯 **Mục tiêu:** hiểu authentication browser dùng Laravel session + CSRF, khởi tạo user khi load app và xử lý
login/logout.

## 1. Browser auth không phải API key

Admin SPA dùng Laravel web-guard session cookie. Axios gửi cookie bằng `withCredentials`; state-changing request
được bảo vệ CSRF qua `XSRF-TOKEN` và `X-XSRF-TOKEN`. Không phát hành, lưu `localStorage` hay gửi bearer API key
từ FE. API keys phục vụ CLI/CI clients.

## 2. Bootstrap session

`src/lib/auth.tsx` cung cấp `AuthProvider` và `useAuth`. Query key `['auth', 'user']` khởi tạo CSRF cookie rồi
gọi `GET /auth/user`:

```tsx
const userQuery = useQuery({
  queryKey: ['auth', 'user'],
  queryFn: async () => {
    await ensureCsrf();
    const { data } = await api.get<ApiEnvelope<User>>('/auth/user');
    return data.data;
  },
  retry: false,
});
```

Code thật có nhánh xử lý 401 để trả `null`; lỗi mạng/5xx không nên được coi như “đã logout”. Không sao chép
snippet rút gọn thay vì giữ error policy đang có.

## 3. Login/logout

`login()` trong AuthProvider nạp CSRF rồi POST `/auth/login`. Khi thành công, query cache `['auth','user']` được
set ngay để route chuyển sang app. `logout()` POST `/auth/logout`; khi thành công user query về `null` và
`queryClient.clear()` xóa dữ liệu phiên trước.

Login page dùng Mantine `useForm` cho validation email/password, gọi `useAuth().login`, hiển thị lỗi 422 và lỗi
request bằng Alert, đồng thời khóa submit trong lúc mutation pending.

## 4. Protected routes

`RequireAuth` trong `App.tsx` phân biệt 3 trạng thái:

1. `isLoading`: loader, không nháy Login trước khi session xác định;
2. `user === null`: LoginPage;
3. có user: `<Outlet />`.

Tránh gate chỉ dựa vào `user` nếu bootstrap còn đang chạy.

## 5. Role là UX, không phải security boundary

FE có thể ẩn navigation/action cho viewer để tránh nhầm lẫn. Server phải kiểm tra role/permission trên mọi API
ghi. Không coi `adminOnly` trong `Layout` hoặc disabled button là biện pháp authorization.

## Kiểm tra

- Reload trang khi đã login vẫn khôi phục session.
- Login sai cho lỗi xác định, không vào dashboard.
- Logout xóa thông tin user và dữ liệu query cũ.
- Request POST/PUT/PATCH/DELETE mang CSRF header theo cấu hình Axios/Laravel.

👉 Tiếp theo: [06 — React Query và trạng thái API](06-react-query-state.md).
