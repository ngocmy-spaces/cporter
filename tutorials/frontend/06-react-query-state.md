# FE 06 — React Query và trạng thái API

🎯 **Mục tiêu:** quản lý server state, cache và polling mà không tạo dữ liệu stale hoặc che mất lỗi.

## 1. QueryClient defaults

`src/lib/queryClient.ts` cấu hình stale time 10 giây, retry một lần và tắt refetch khi focus. App dùng cùng một
`QueryClient` được tạo ở module scope và truyền qua provider.

## 2. Query key và params

Query key nhận diện chính xác một response. Nếu endpoint phụ thuộc pagination/search/status thì đưa chúng vào key:

```tsx
useQuery({
  queryKey: ['projects', 'list', { page, search, status }],
  queryFn: async () =>
    (await api.get('/projects', { params: { page, search, status } })).data,
});
```

Đây là nguyên tắc minh họa theo `ProjectsPage`. Mọi component chia sẻ key phải chia sẻ cả response shape. Không
đưa password, API token hay secret vào query key.

## 3. Mutation và cache invalidation

Mutation cập nhật dữ liệu backend; sau thành công hãy invalidate các query liên quan:

```tsx
const mutation = useMutation({
  mutationFn: (payload: CreateProjectPayload) => api.post('/projects', payload),
  onSuccess: () => queryClient.invalidateQueries({ queryKey: ['projects'] }),
});
```

Khi response trả entity đầy đủ, có thể `setQueryData` cho key cụ thể rồi invalidate list nếu list membership/
pagination có thể đổi. Tránh cập nhật một phần cache không đúng shape.

## 4. Polling

cPanel không dùng websocket. Các page feed poll có chu kỳ (ví dụ dashboard 15 giây); `DeploymentDrawer` poll
deployment đang hoạt động nhanh hơn và dừng khi status terminal. Poll interval phải phù hợp tải, chỉ bật khi dữ
liệu cần freshness.

## 5. Loading/error/empty/data

Dùng `PanelBody` cho query-backed surface: spinner/skeleton khi loading, retryable Alert khi error, children
khi data sẵn sàng. Sau đó page tự hiển thị `EmptyState` khi array thực sự rỗng. Không dùng `data ?? []` để kết
luận empty trước khi phân biệt query error/loading.

Dashboard nạp nhiều query nên phải hiển thị lỗi nếu một trong những query thiết yếu thất bại; không render zero
cards như thể đó là dữ liệu thật.

## Kiểm tra

- Query key phân biệt mọi filter/params.
- Mutation success refresh đúng list/detail.
- Error có retry hoặc feedback.
- Polling dừng khi deployment terminal.

👉 Tiếp theo: [07 — Mantine forms và feedback](07-forms-feedback.md).
