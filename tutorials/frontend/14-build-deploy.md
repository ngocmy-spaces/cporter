# FE 14 — Build, kiểm tra và deploy

🎯 **Mục tiêu:** chạy quality checks, build Vite production và đóng gói frontend cùng Laravel API.

## 1. Chất lượng trước build

Từ repository root:

```bash
pnpm --filter @cporter/web typecheck
pnpm --filter @cporter/web lint
pnpm --filter @cporter/web build
```

`build` chạy TypeScript build (`tsc -b`) rồi `vite build`; output mặc định vào `apps/web/dist`. `lint` chạy ESLint.
Repo chưa có unit/component test suite FE riêng; thực hiện smoke test bằng browser:

- login/logout và refresh session;
- mở public docs khi chưa login;
- đọc project list/detail;
- tạo/đổi một record ở môi trường dev và kiểm tra validation/error;
- kiểm tra deployment polling, light/dark và mobile nav.

## 2. Artifact cPorter

Vite output đơn lẻ chưa phải artifact phát hành đầy đủ. Root script `pnpm build:artifact` orchestrate build FE,
copy dist vào Laravel public directory, rồi đóng gói API thành zip trong `build/out/`. Theo dõi output và SHA-256
được in ra; deployment flow chi tiết nằm trong
[docs/DEPLOYMENT-CPANEL.md](../../docs/DEPLOYMENT-CPANEL.md) và [tutorial Backend 17](../backend/17-build-cai-dat-cpanel.md).

Không commit `apps/web/dist`, Laravel `public/build` hoặc zip artifacts vào source control.

## 3. Cấu hình version và API

`vite.config.ts` inject version package vào `VITE_APP_VERSION` để header badge khớp release. FE mặc định gọi
same-origin `/api/v1`; production không cần browser trỏ API tới host dev. Mọi `VITE_*` là public build-time value,
không chứa secrets.

## 4. SPA fallback và hosting

Laravel production phục vụ SPA entrypoint và API cùng origin, giúp session cookies/CSRF vận hành theo cấu hình
server. Nếu đổi web server/rewrite rules, cần bảo đảm direct navigation tới `/projects`, `/docs/overview`,
`/projects/:slug` trả SPA shell thay vì 404; `/api/*` phải vẫn tới Laravel routes.

Đừng cấu hình fallback HTML cho API errors; endpoint API phải trả JSON/status thực.

## 5. Release checklist

- [ ] Typecheck, lint và production build đều pass.
- [ ] Environment không có key/secret public.
- [ ] Artifact chứa asset FE trong đúng Laravel public path.
- [ ] SHA-256 lưu cùng release artifact để xác minh.
- [ ] Smoke test login, route deep link, public docs và API sau deploy.
- [ ] Rollback có thể quay về artifact trước đó.

## Hoàn thành lộ trình Frontend

Bạn đã đi từ bootstrap tới build phát hành. Khi thêm tính năng mới: bắt đầu từ API contract, tạo domain type,
định nghĩa query key, implement Mantine UI states, thêm role UX có backend authorization tương ứng, rồi chạy
typecheck/lint/build và kiểm tra browser.
