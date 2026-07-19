# cPorter Frontend (apps/web)

Admin SPA for cPorter. **Stack:** React 19 + Vite + TypeScript + **Mantine v9** (the only UI kit) +
React Router 7 + TanStack Query 5 + `@tabler/icons-react`.

> **Rule #1: Mantine-only.** No Tailwind, no other UI kit, no home-grown CSS framework.
> Style with Mantine components + style props + theme; when you need scoped CSS, use CSS Modules with Mantine's CSS variables.

## Structure

```
apps/web/
├── postcss.config.cjs        # postcss-preset-mantine + breakpoint vars
├── src/
│   ├── main.tsx              # import styles.css → ColorSchemeScript + MantineProvider + QueryClient + Router
│   ├── theme.ts              # createTheme (primaryColor indigo, radius, font)
│   ├── index.css             # only small globals (Mantine styles.css handles the reset)
│   ├── App.tsx               # routes (admin app + public /docs area)
│   ├── components/
│   │   ├── Layout.tsx            # AppShell (header + navbar)
│   │   ├── ColorSchemeToggle.tsx # dark/light toggle
│   │   ├── DeploymentDrawer.tsx  # step timeline (success/failed/warning) + poll to terminal status
│   │   ├── ProjectEnvPanel.tsx   # admin-only Environment tab: encrypted env-var editor + .env import/adopt
│   │   ├── StatusBadge.tsx
│   │   ├── Placeholder.tsx
│   │   └── docs/CodeBlock.tsx     # used by the /docs pages
│   ├── pages/                # admin: Dashboard, Projects, ProjectDetail, Deployments,
│   │   │                     #        Releases, Logs, Settings, Users, ApiKeys, Login
│   │   └── docs/             # public docs: Overview, Quickstart, GithubAction, Mcp, ApiReference
│   └── lib/
│       ├── api.ts            # axios client, base /api/v1, bearer token
│       ├── auth.tsx          # session auth context (login/logout/user)
│       ├── format.ts         # formatting helpers
│       ├── parseEnvText.ts   # parse pasted/uploaded .env text → {key,value}[] (comments/quotes-aware)
│       ├── types.ts          # shared FE types
│       └── queryClient.ts
```

> Mantine sub-packages in use (import each one's `styles.css` **after** `@mantine/core`):
> `@mantine/form`, `@mantine/modals`, `@mantine/notifications`.

## Mantine setup (already configured)

- **CSS import order matters:** `@mantine/core/styles.css` **before** any other CSS/app code (in `main.tsx`).
  For any sub-package you use, import its `styles.css` **after** core.
- Provider: `<ColorSchemeScript defaultColorScheme="auto" />` + `<MantineProvider theme={theme} defaultColorScheme="auto">`.
- Edit design tokens in `theme.ts` (don't hardcode hex/px in components).
- Dark/light: `defaultColorScheme="auto"`; toggle with `useMantineColorScheme` (the button is already in the header).

## Leveraging the Mantine docs via `llms.txt`

Mantine ships LLM-optimized docs — **this is the source of truth for the exact version in use**:

- Index: `https://mantine.dev/llms.txt`
- Per-topic pages: `https://mantine.dev/llms/<section>-<kebab-name>.md`
  (e.g. `core-app-shell.md`, `core-table.md`, `form-use-form.md`, `x-notifications.md`, `theming-colors.md`)
- Everything combined (large, rarely needed): `https://mantine.dev/llms-full.txt`

**Rule:** unsure about a prop/component → `WebFetch`/`curl` the matching `.md` page and use the documented API,
**don't guess**.

## AI agent support

- **Skill `mantine-ui`** ([.claude/skills/mantine-ui/](../.claude/skills/mantine-ui/SKILL.md)) — load it before
  doing UI work: the Mantine-only rule, `llms.txt` URL map, lookup workflow, setup facts, component cheat-sheet.
- **Agent `mantine-ui-engineer`** ([.claude/agents/mantine-ui-engineer.md](../.claude/agents/mantine-ui-engineer.md))
  — a subagent specialized in building Mantine UI that self-verifies props against the docs + runs build/lint. Delegate every large FE task here.

## Conventions

- **Icons:** `import { IconRocket } from '@tabler/icons-react'` → `<IconRocket size={18} stroke={1.5} />`.
- **Router link:** `component={Link}` on `NavLink`/`Anchor`/`Button`.
- **Responsive:** props accept an object keyed by breakpoint, e.g. `cols={{ base: 1, sm: 2, lg: 4 }}`.
- **Data:** TanStack Query + `@/lib/api`; for long-running resources (deployment) poll with `refetchInterval`
  (cPanel has no websocket).

## Dev & Build

```bash
pnpm dev:web                       # Vite dev, proxy /api → :8000
pnpm --filter @cporter/web build   # tsc -b && vite build  (must pass)
pnpm --filter @cporter/web lint    # eslint (must pass)
```

On deploy: `dist/` is copied into `apps/api/public` (see [SPEC §14](SPEC.md#14-deploying-cporter-itself-self-hosting)).
