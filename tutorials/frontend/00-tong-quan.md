# FE 00 — Tổng quan kiến trúc Frontend

🎯 **Mục tiêu:** hiểu SPA, provider tree, router, page, API client và cache trước khi thêm tính năng.

## 1. Vai trò và stack

`apps/web` là admin SPA; Vite dev server phục vụ FE local, còn Laravel phục vụ production build từ
`apps/api/public`. FE không sở hữu nghiệp vụ deploy: nó đọc/gửi JSON qua `/api/v1`.

| Trách nhiệm | Công nghệ |
|---|---|
| Component/UI/theme | React 19 + Mantine v9 |
| Development/build | Vite 6 + TypeScript |
| URL/navigation | React Router 7 |
| Server state/cache/poll | TanStack Query 5 |
| HTTP | Axios instance tại `src/lib/api.ts` |
| Icon | `@tabler/icons-react` |
| Form | `@mantine/form` |

## 2. Request đến UI

```text
URL
 └─ App.tsx: public docs hoặc auth-gated admin route
     └─ page: useQuery / useMutation
         └─ @/lib/api.ts: Axios, baseURL /api/v1, cookies + CSRF
             └─ Laravel API: JSON response
                 └─ React Query cache → Mantine component
```

Forms dùng `useForm`; component dùng lại gồm `PanelBody`, `EmptyState`, `StatusBadge`,
`DeploymentDrawer`, `ProjectEnvPanel` và helper ở `lib/feedback.tsx`.

## 3. Cấu trúc mã

```text
apps/web/src/
├── main.tsx            # providers + CSS order
├── App.tsx             # route tree + auth gate
├── theme.ts            # Mantine design tokens
├── components/         # layout và shared UI
├── lib/                # API, auth, types, feedback, format
└── pages/              # admin pages + public docs pages
```

Alias `@/` trỏ tới `src/`. Feature page chứa orchestration của route; shared UI và helper đặt trong `components/`
và `lib/`, tránh page này nhập logic state của page khác.

## 4. Ba loại state

- **Server state:** project, deployment, user/session; dùng React Query, không mirror vào `useState` nếu không cần.
- **UI state:** modal, filter, tab, selection; dùng local state hoặc Mantine hooks.
- **Form draft:** giá trị đang nhập; dùng Mantine `useForm`, gửi khi submit.

Phân biệt chúng tránh dữ liệu có nhiều bản sao và UI stale sau mutation.

## 5. Đọc luồng có sẵn

Đọc theo thứ tự: `main.tsx` → `App.tsx` → `components/Layout.tsx` → `lib/api.ts`, `auth.tsx`,
`queryClient.ts` → page đơn giản `LoginPage.tsx` → `DashboardPage.tsx`.
Tham khảo thêm [docs/FRONTEND.md](../../docs/FRONTEND.md).

## Checklist

- [ ] Phân biệt server state, UI state và form draft.
- [ ] Biết request đi từ page qua Axios đến Laravel.
- [ ] Biết provider và auth gate nằm ở đâu.
- [ ] Không đưa bearer API key CI vào browser.

👉 Tiếp theo: [01 — Setup môi trường FE](01-setup-local.md).
