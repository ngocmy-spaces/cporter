# cPorter — Hướng dẫn xây dựng từ đầu

Bộ tutorial hướng dẫn Backend và Frontend của cPorter theo hai nhánh riêng, từ setup local đến build tính
năng và đóng gói để triển khai. Người học FE có thể dùng API Laravel hiện có; người học BE không cần học React
để hiểu deploy engine.

> **Phạm vi:** Backend nằm trong `apps/api`; Frontend là admin SPA trong `apps/web`. FE dùng React 19,
> TypeScript, Mantine v9, React Router 7 và TanStack Query 5. Dùng Mantine làm UI kit duy nhất.

> Đây là tutorial đối chiếu với implementation hiện có, không phải bản sao thay thế tài liệu hợp đồng.
> Dùng [docs/API.md](../docs/API.md) cho HTTP contract, [docs/SPEC.md](../docs/SPEC.md) cho quyết định
> kiến trúc, [TASKS.md](../TASKS.md) cho lịch sử phase và trạng thái tính năng. Các ví dụ được ghi rõ
> khi rút gọn; mã nguồn trong `apps/api` là chuẩn cuối cùng nếu có khác biệt.

## Lộ trình Backend

| Bài | Chủ đề | Mốc hoàn thành |
|---|---|---|
| [00 — Tổng quan kiến trúc](backend/00-tong-quan-kien-truc.md) | Bài toán cPanel, các lớp backend, luồng deploy | Hiểu vai trò của request, worker, filesystem và DB |
| [01 — Setup môi trường local](backend/01-setup-local.md) | Docker Compose hoặc cài PHP/MySQL thủ công | Gọi được health endpoint, migrate và chạy test |
| [02 — Khởi tạo Laravel và cấu hình](backend/02-khoi-tao-laravel-cau-hinh.md) | Composer, bootstrap, config, routes, service container | Chạy được app Laravel và cấu hình theo môi trường |
| [03 — Domain model và migrations](backend/03-domain-model-migrations.md) | Bảng nghiệp vụ, quan hệ, enum, casts | Schema và Eloquent model thể hiện đúng vòng đời deploy |
| [04 — Session, đăng nhập và vai trò](backend/04-session-dang-nhap-role.md) | Admin session, CSRF, role admin/viewer | Đăng nhập được và viewer không ghi được dữ liệu |
| [05 — API key, scope và capability](backend/05-api-key-scope-capability.md) | Token CI, hash, scope, ràng buộc project | API key được xác thực và giới hạn đúng quyền |
| [06 — Path jail và cấu hình an toàn](backend/06-path-jail-settings.md) | Allow-list, chuẩn hóa đường dẫn, symlink escape | Chặn đường dẫn nằm ngoài vùng được phép |
| [07 — Storage adapter và ZIP](backend/07-storage-adapter-zip.md) | Interface filesystem, giải nén, release/shared/current | Adapter lưu, trích xuất và kích hoạt release an toàn |
| [08 — Project API](backend/08-project-api.md) | CRUD, preflight, clone, hooks và shared paths | Project được validate trước khi deploy |
| [09 — Deploy API và upload](backend/09-deploy-api-upload.md) | Upload đơn/chunk, SHA-256, idempotency | API tạo deployment từ artifact hợp lệ |
| [10 — Deploy pipeline](backend/10-deploy-pipeline.md) | Job, engine, lock, step log, staging và activate | Release được triển khai theo pipeline có thể quan sát |
| [11 — Health check và rollback](backend/11-health-check-rollback.md) | Health monitor, rollback thủ công/tự động | Lỗi sau activate được xử lý theo policy |
| [12 — CommandRunner, hooks và cron worker](backend/12-command-runner-hooks-cron.md) | Shell trong cron, hook isolation, finalize | Hook Laravel chạy ngoài HTTP request |
| [13 — Chunked upload và FIFO deploy](backend/13-chunked-upload-fifo.md) | Upload nhiều phần, claim lock, hàng đợi từng project | Deploy đồng thời được xếp hàng và phục hồi đúng |
| [14 — Scheduler, housekeeping và retention](backend/14-scheduler-housekeeping.md) | Scheduler, timeout, prune, heartbeat | Tác vụ vận hành tự phục hồi và dọn tài nguyên an toàn |
| [15 — Env vars, audit và webhook](backend/15-env-audit-webhook.md) | Mã hóa secrets, file `.env`, audit, HMAC | Không làm lộ secret và xác minh webhook đúng |
| [16 — Kiểm thử Backend](backend/16-kiem-thu-backend.md) | Pest, feature/unit test, filesystem tạm | Chạy test theo lớp và xác nhận các luồng chính |
| [17 — Đóng gói và cài lần đầu](backend/17-build-cai-dat-cpanel.md) | Composer production, artifact, bootstrap cPanel | Cài Backend production từ artifact |
| [18 — Cron và vận hành production](backend/18-van-hanh-cap-nhat.md) | Cron, log, heartbeat, nâng cấp và khắc phục sự cố | Vận hành hoặc cập nhật cPorter có checklist |

## Lộ trình Frontend

Lộ trình Frontend đi từ cấu trúc SPA đến tính năng, UX states và build tích hợp Laravel:

| Bài | Chủ đề | Mốc hoàn thành |
|---|---|---|
| [00 — Tổng quan Frontend](frontend/00-tong-quan.md) | Kiến trúc SPA, luồng dữ liệu, route/page | Hiểu đường đi từ route tới API |
| [01 — Setup môi trường FE](frontend/01-setup-local.md) | pnpm, Vite, API proxy | Chạy ứng dụng ở `localhost:5173` |
| [02 — Bootstrap, Mantine và theme](frontend/02-bootstrap-theme.md) | entrypoint, CSS order, provider, theme | Tùy chỉnh đúng theme Mantine |
| [03 — Routing, AppShell và navigation](frontend/03-routing-shell.md) | public/admin routes, Layout, responsive nav | Thêm route đúng khu vực |
| [04 — API client và TypeScript types](frontend/04-api-client-types.md) | Axios, envelopes, domain types | Gọi API với types đúng |
| [05 — Session auth và Login](frontend/05-session-auth-login.md) | cookie, CSRF/XSRF, auth gate | Đăng nhập theo session |
| [06 — React Query và trạng thái API](frontend/06-react-query-state.md) | query keys, polling, mutation, invalidation | Đồng bộ UI với API |
| [07 — Mantine forms và feedback](frontend/07-forms-feedback.md) | useForm, API 422, notifications | Form có validation và lỗi rõ ràng |
| [08 — Dashboard](frontend/08-dashboard.md) | stats, alerts, deployments feed | Tạo overview từ dữ liệu thật |
| [09 — Projects](frontend/09-projects.md) | danh sách, tìm kiếm, pagination, create | Quản lý project |
| [10 — Project detail và Environment](frontend/10-project-detail-environment.md) | tabs, hooks, shared paths, secrets | Hoàn thiện màn hình project |
| [11 — Deployments và timeline](frontend/11-deployments-timeline.md) | filters, polling, DeploymentDrawer | Theo dõi deploy |
| [12 — Admin và public docs](frontend/12-admin-public-docs.md) | API keys, users, settings, audit, docs | Hoàn thiện bề mặt quản trị |
| [13 — Responsive, accessibility và UX states](frontend/13-responsive-accessibility.md) | mobile, keyboard, loading/error/empty | UI rõ ràng ở mọi trạng thái |
| [14 — Build, kiểm tra và deploy](frontend/14-build-deploy.md) | typecheck, lint, Vite build, Laravel artifact | Đưa SPA vào artifact |

Xem [mục lục Frontend](frontend/README.md) để bắt đầu theo thứ tự.

Các bài được nhóm theo phase:

1. **Nền tảng:** 00–03.
2. **Auth và ranh giới an toàn:** 04–06.
3. **Storage và deploy core:** 07–11.
4. **Tác vụ nền và độ tin cậy:** 12–15.
5. **Kiểm thử và đưa vào production:** 16–18.

## Quy ước trình bày

Mỗi bài đi từ mục tiêu và kiến thức nền đến từng bước, file cần tạo/sửa, lý do thiết kế, lệnh kiểm tra,
lỗi thường gặp và checklist hoàn thành. Đường dẫn trong tiêu đề code tính từ thư mục gốc repository.
Code ví dụ được giữ ngắn khi implementation có nhiều nhánh; bài sẽ liên kết đúng file để đọc phần đầy đủ.

Luồng gợi ý là đi tuần tự từ bài 00. Có thể bỏ qua setup thủ công nếu dùng Docker Compose, nhưng không nên
bỏ qua bài Path Jail hoặc bảo vệ secrets trước khi tự triển khai filesystem/API.

## Môi trường

| Công cụ | Yêu cầu | Vai trò |
|---|---|---|
| PHP | 8.2+; target cPanel hiện hướng tới PHP 8.3 | Chạy Laravel |
| Composer | 2.x | Cài dependency Backend |
| MySQL | 5.7+ / 8 | Lưu cấu hình, deployments, queue và session |
| Node | 20+ | Frontend và build artifact |
| pnpm | 11+ | Workspace JavaScript |
| Docker Compose | Khuyến nghị cho môi trường local | Cung cấp PHP, MySQL, API và worker đồng bộ |

Backend không cần pnpm để chạy độc lập. Khi làm việc trong monorepo, `pnpm` chỉ cần cho frontend và
quy trình build artifact có đóng gói static frontend vào `apps/api/public`.

## Bắt đầu

Chọn [Backend 00](backend/00-tong-quan-kien-truc.md) hoặc [Frontend 00](frontend/00-tong-quan.md), sau đó đi
theo bài setup tương ứng.
