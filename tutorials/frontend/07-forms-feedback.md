# FE 07 — Mantine forms và feedback

🎯 **Mục tiêu:** xây form có type, validation phía client, lỗi field từ Laravel và trạng thái thành công/thất bại
nhất quán.

## 1. Form values có type

Định nghĩa interface cho input form thay vì bind trực tiếp domain object:

```tsx
interface LoginFormValues {
  email: string;
  password: string;
}

const form = useForm<LoginFormValues>({
  initialValues: { email: '', password: '' },
  validate: {
    email: (value) => (/^\S+@\S+\.\S+$/.test(value) ? null : 'Enter a valid email'),
    password: (value) => (value.length ? null : 'Password is required'),
  },
});
```

Xem API Mantine v9 tại [use-form](https://mantine.dev/llms/form-use-form.md). Gắn `form.getInputProps()` vào
Mantine inputs để đồng bộ value, blur và error.

## 2. Server validation (422)

Client validation giúp feedback nhanh, không thay backend validation. Khi Laravel trả 422, dùng helper
`applyFormErrors(error, form)` ở `lib/feedback.tsx` để map `errors[field]` vào form; các lỗi khác dùng
`notifyError`/`notifySuccess`. Không nuốt lỗi hoặc báo thành công khi request thất bại.

Nếu API field name khác form field name (nested array, rename payload), map lỗi trước khi gọi helper. Ví dụ
ProjectDetail xử lý field hook riêng.

## 3. Payload không nhất thiết là form state

Chuẩn hóa input trước khi submit: trim tên/path, đổi `''` thành `undefined` cho optional field, chuyển ID string
sang number, bỏ row trống. Giữ form values đủ để người dùng sửa; chỉ tạo payload chuẩn tại `mutationFn`.

Với secret, tránh in form values/log vào console hoặc notification.

## 4. Pending và feedback

- Submit button dùng mutation `isPending` để hiện loading/khóa gửi lặp.
- Modal đóng/reset khi success, không reset ngay khi lỗi.
- Success/error notification ngắn, có nội dung actionable.
- Action phá hủy dùng confirm modal, nói rõ tác động (ví dụ revoke key sẽ làm CI mất quyền).
- Form invalid giữ nguyên input để người dùng sửa.

`notifySuccess`, `notifyError`, `applyFormErrors` trong `src/lib/feedback.tsx` là pattern chung.

## 5. Mantine component docs

Ví dụ Login dùng `TextInput`, `PasswordInput`, `Alert`, `Button`; xác minh API trong
[TextInput docs](https://mantine.dev/llms/core-text-input.md) và docs [Button](https://mantine.dev/llms/core-button.md).
Không thêm CSS framework khác cho layout; dùng Mantine components/style props/theme.

## Kiểm tra

- Client validation báo lỗi field trước request.
- Server 422 hiển thị đúng field.
- 5xx/network error hiển thị lỗi chung.
- Pending state ngăn submit lặp.

👉 Tiếp theo: [08 — Dashboard](08-dashboard.md).
