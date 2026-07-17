# cPorter Frontend (apps/web)

Admin SPA cho cPorter. **Stack:** React 19 + Vite + TypeScript + **Mantine v9** (UI kit duy nhất) +
React Router 7 + TanStack Query 5 + `@tabler/icons-react`.

> **Nguyên tắc số 1: Mantine-only.** Không Tailwind, không UI kit khác, không CSS framework tự chế.
> Style bằng Mantine component + style props + theme; cần scoped CSS thì dùng CSS Modules với biến CSS của Mantine.

## Cấu trúc

```
apps/web/
├── postcss.config.cjs        # postcss-preset-mantine + breakpoint vars
├── src/
│   ├── main.tsx              # import styles.css → ColorSchemeScript + MantineProvider + QueryClient + Router
│   ├── theme.ts              # createTheme (primaryColor indigo, radius, font)
│   ├── index.css             # chỉ global nhỏ (Mantine styles.css lo phần reset)
│   ├── App.tsx               # routes
│   ├── components/
│   │   ├── Layout.tsx        # AppShell (header + navbar + color-scheme toggle)
│   │   └── Placeholder.tsx
│   ├── pages/                # Dashboard + 7 trang (Projects, Deployments, …)
│   └── lib/
│       ├── api.ts            # axios client, base /api/v1, bearer token
│       └── queryClient.ts
```

## Setup Mantine (đã cấu hình)

- **Thứ tự import CSS quan trọng:** `@mantine/core/styles.css` **trước** mọi CSS/app khác (trong `main.tsx`).
  Sub-package nào dùng thì import `styles.css` của nó **sau** core.
- Provider: `<ColorSchemeScript defaultColorScheme="auto" />` + `<MantineProvider theme={theme} defaultColorScheme="auto">`.
- Design tokens sửa ở `theme.ts` (đừng hardcode hex/px trong component).
- Dark/light: `defaultColorScheme="auto"`; toggle bằng `useMantineColorScheme` (đã có nút trên header).

## Khai thác tài liệu Mantine qua `llms.txt`

Mantine có bộ docs tối ưu cho LLM — **đây là nguồn chân lý cho đúng version đang dùng**:

- Index: `https://mantine.dev/llms.txt`
- Trang từng chủ đề: `https://mantine.dev/llms/<section>-<kebab-name>.md`
  (vd `core-app-shell.md`, `core-table.md`, `form-use-form.md`, `x-notifications.md`, `theming-colors.md`)
- Gộp tất cả (lớn, ít dùng): `https://mantine.dev/llms-full.txt`

**Quy tắc:** không chắc prop/component → `WebFetch`/`curl` trang `.md` tương ứng rồi dùng đúng API tài liệu,
**không đoán**.

## Hỗ trợ AI agent

- **Skill `mantine-ui`** ([.claude/skills/mantine-ui/](../.claude/skills/mantine-ui/SKILL.md)) — nạp trước
  khi làm UI: rule Mantine-only, bản đồ URL `llms.txt`, workflow tra cứu, setup facts, cheat-sheet component.
- **Agent `mantine-ui-engineer`** ([.claude/agents/mantine-ui-engineer.md](../.claude/agents/mantine-ui-engineer.md))
  — subagent chuyên build UI Mantine, tự verify prop qua docs + chạy build/lint. Delegate mọi task FE lớn vào đây.

## Conventions

- **Icons:** `import { IconRocket } from '@tabler/icons-react'` → `<IconRocket size={18} stroke={1.5} />`.
- **Router link:** `component={Link}` trên `NavLink`/`Anchor`/`Button`.
- **Responsive:** props nhận object theo breakpoint, vd `cols={{ base: 1, sm: 2, lg: 4 }}`.
- **Data:** TanStack Query + `@/lib/api`; resource chạy dài (deployment) thì poll bằng `refetchInterval`
  (cPanel không có websocket).

## Dev & Build

```bash
pnpm dev:web                       # Vite dev, proxy /api → :8000
pnpm --filter @cporter/web build   # tsc -b && vite build  (bắt buộc pass)
pnpm --filter @cporter/web lint    # eslint (bắt buộc pass)
```

Khi deploy: `dist/` được copy vào `apps/api/public` (xem [SPEC §14](SPEC.md#14-deploy-chính-bản-thân-cporter-self-hosting)).
