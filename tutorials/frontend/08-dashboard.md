# FE 08 — Dashboard

🎯 **Mục tiêu:** kết hợp project/deployment data thành overview, alert và recent activity mà không trình bày số 0
giả.

## 1. Dữ liệu đầu vào

`DashboardPage` tải project tại `GET /projects` và feed deployment tại `GET /deployments`. Deployment query
poll mỗi 15 giây; cùng feed có thể được share bởi `ProjectsPage`/`DeploymentsPage` qua key `['deployments']`.

## 2. Tính toán presentation

Dashboard hiện tính:

- tổng project và project `active`;
- deployments tạo trong 24 giờ gần nhất;
- success rate trong các deployment terminal (success/failed/rolled_back) ở khoảng 24 giờ;
- tám deployment mới nhất;
- số deployment đang chạy, failure gần đây, unhealthy project;
- cờ deployment “stuck” nếu in-flight hơn 10 phút.

Đây là summary client-side dựa trên feed trả về. Nếu backend giới hạn feed, label không được ám chỉ thống kê
toàn lịch sử. Dashboard đang dựa vào tập deployments được endpoint trả về; giữ nội dung explanatory text khớp
phạm vi đó.

## 3. Error/loading/empty

Dashboard cần cả projects và deployments, nên lỗi một query làm overview không đầy đủ; page hiển thị Alert + Retry,
không hiện success rate `0%` hoặc empty table sai. Skeleton dùng khi loading; `—` dùng khi mẫu chưa đủ để tính
rate; EmptyState dành cho feed rỗng hợp lệ.

## 4. Mở deployment detail

Recent deployments và alert có thể chọn deployment ID, mở `DeploymentDrawer` để xem timeline. UI state ID thuộc
page, nội dung deployment thuộc React Query detail key.

## 5. Cách mở rộng

Khi thêm KPI:

1. xác định nguồn API và giới hạn dataset;
2. mô tả rõ cửa sổ thời gian/mẫu số;
3. phân biệt loading, không đủ dữ liệu và giá trị 0;
4. thêm link/action giúp xử lý alert;
5. tránh fetch N+1 nếu endpoint feed đã bao gồm quan hệ cần thiết.

## Kiểm tra

- API error không tạo dashboard zero-state giả.
- Tính success rate loại bỏ status chưa terminal.
- Unhealthy/failed/in-flight alerts khớp status enum.
- Recent deployment mở được drawer.

👉 Tiếp theo: [09 — Projects](09-projects.md).
