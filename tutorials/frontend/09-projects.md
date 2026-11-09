# FE 09 — Projects

🎯 **Mục tiêu:** xây danh sách project có search, status filter, pagination, thông tin deploy gần nhất và tạo project
theo path được API cho phép.

## 1. List query

`ProjectsPage` dùng `['projects','list',{page,search,status}]`; search được debounce 300 ms, page reset về 1 khi
search/status đổi. Request gửi `page`, `per_page=20`, và tham số filter khi cần. Response gồm `{data, meta}` để
render `Pagination`.

Giữ filters trong state page; không đưa tất cả vào component table con nếu điều đó làm query key không đầy đủ.

## 2. Hiển thị row

Trang nối latest deployment theo `project_id` từ feed mới nhất (`['deployments']`), hiển thị health/deployment
status badge, release version, disk usage và thời gian tương đối. Click project điều hướng tới `/projects/:slug`.
Empty state khác nhau cho “chưa có project” và “không có kết quả lọc”.

## 3. Tạo project

Create modal dùng Mantine `useForm`; capability query `['system','capabilities']` tải allowed base paths. UI
ghép base prefix + subpath thành `base_path`; payload có type, docroot, release retention, rollback triggers,
health URL và shared paths.

```ts
const payload = {
  name: values.name,
  base_path: composeBasePath(values.base_dir, values.base_subpath),
  type: values.type,
  keep_releases: values.keep_releases,
  auto_rollback_on: values.auto_rollback_on,
  shared_paths: values.shared_paths.filter((item) => item.path.trim()),
};
```

Snippet chỉ minh họa cách tách form state khỏi API payload; tham khảo function trong `ProjectsPage.tsx` để giữ
đầy đủ optional fields.

**Quan trọng:** base path client-side không phải sandbox. Backend phải validate path jail; FE không được coi
capability response hay UI dropdown là security boundary.

## 4. Mutation

Sau create thành công, invalidate `['projects']`, reset form, đóng modal và thông báo success. Khi validation lỗi,
đưa Laravel 422 về input; lỗi khác hiển thị notification. Tạo project có thể cấp slug khác từ server, nên mở detail
bằng object response thay vì tự đoán slug.

## 5. Mantine table và pagination

Dùng `Table`/`Pagination` Mantine; table rộng nên có scroll container min width và mobile không ép cột đọc được.
Tra cứu [Table docs](https://mantine.dev/llms/core-table.md) trước khi đổi API component.

## Kiểm tra

- Debounce không gửi request mỗi ký tự.
- Search/status reset page.
- Pagination lấy current/last page từ `meta`.
- Create success refresh list; error giữ form data.
- Viewer gating chỉ là UX, backend vẫn kiểm tra quyền.

👉 Tiếp theo: [10 — Project detail và Environment](10-project-detail-environment.md).
