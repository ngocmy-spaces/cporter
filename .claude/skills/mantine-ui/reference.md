# Mantine reference (cPorter apps/web)

Cached facts so you don't re-derive setup. Source of truth is still
`https://mantine.dev/llms/*.md` — fetch it when unsure.

## Installed

- Runtime: `@mantine/core`, `@mantine/hooks` (v9.x), `@tabler/icons-react`.
- Build: `postcss`, `postcss-preset-mantine`, `postcss-simple-vars` (dev).
- Peers already present: `react`/`react-dom` 19, `react-router-dom` 7, `@tanstack/react-query` 5.

## Wiring (already done — files to edit, not recreate)

- `apps/web/postcss.config.cjs` — Mantine PostCSS preset + breakpoint vars:
  ```js
  module.exports = {
    plugins: {
      'postcss-preset-mantine': {},
      'postcss-simple-vars': {
        variables: {
          'mantine-breakpoint-xs': '36em',
          'mantine-breakpoint-sm': '48em',
          'mantine-breakpoint-md': '62em',
          'mantine-breakpoint-lg': '75em',
          'mantine-breakpoint-xl': '88em',
        },
      },
    },
  };
  ```
- `apps/web/src/main.tsx` — **import order matters**: `@mantine/core/styles.css` FIRST, then app
  CSS, then render `<ColorSchemeScript defaultColorScheme="auto" />` + `<MantineProvider theme={theme} defaultColorScheme="auto">`.
  Any new sub-package's `styles.css` must be imported AFTER `@mantine/core/styles.css`.
- `apps/web/src/theme.ts` — `createTheme({ primaryColor: 'indigo', defaultRadius: 'md', fontFamily })`.
  Change design tokens here; see `https://mantine.dev/llms/theming-theme-object.md`.
- `apps/web/src/components/Layout.tsx` — `AppShell` (header + navbar) is the shell; add nav items to `NAV`.

## Adding a Mantine sub-package

1. `pnpm --filter @cporter/web add @mantine/<pkg>`
2. Import its stylesheet in `main.tsx` **after** core: `import '@mantine/<pkg>/styles.css';`
3. Some packages need a provider/container near the root:
   - `@mantine/notifications` → import `@mantine/notifications/styles.css`, add `<Notifications />` inside `MantineProvider`; call via `notifications.show(...)`. Doc: `/llms/x-notifications.md`
   - `@mantine/modals` → wrap with `<ModalsProvider>`; use `modals.open/openConfirmModal`. Doc: `/llms/x-modals.md`
   - `@mantine/form` → no provider; `useForm(...)`. Doc: `/llms/form-use-form.md`
   - `@mantine/dates` → import `@mantine/dates/styles.css`. Doc: `/llms/dates-getting-started.md`
   - `@mantine/charts` → import `@mantine/charts/styles.css`. Doc: `/llms/charts-getting-started.md`
4. Verify build + lint.

## Component cheat-sheet (fetch the doc before using unfamiliar ones)

| Need | Components | Doc |
|---|---|---|
| Layout / shell | `AppShell`, `Container`, `Paper`, `Card` | `core-app-shell.md`, `core-card.md` |
| Flex/spacing | `Group`, `Stack`, `SimpleGrid`, `Grid`, `Flex`, `Space`, `Divider` | `core-group.md`, `core-simple-grid.md` |
| Text | `Title`, `Text`, `Anchor`, `Code`, `Highlight` | `core-text.md`, `core-title.md` |
| Buttons | `Button`, `ActionIcon`, `Menu`, `Tooltip` | `core-button.md`, `core-action-icon.md` |
| Inputs | `TextInput`, `PasswordInput`, `NumberInput`, `Select`, `MultiSelect`, `Checkbox`, `Switch`, `Textarea`, `SegmentedControl` | `core-text-input.md`, `core-select.md` |
| Data | `Table`, `Badge`, `Pill`, `Tabs`, `Accordion`, `Timeline` | `core-table.md`, `core-tabs.md`, `core-timeline.md` |
| Feedback | `Loader`, `Skeleton`, `Alert`, `Progress`, `RingProgress` | `core-alert.md`, `core-skeleton.md` |
| Overlays | `Modal`, `Drawer`, `Popover`, `HoverCard` | `core-modal.md`, `core-drawer.md` |
| Navigation | `NavLink`, `Breadcrumbs`, `Pagination` | `core-nav-link.md`, `core-pagination.md` |
| Notifications/modals | `@mantine/notifications`, `@mantine/modals` | `x-notifications.md`, `x-modals.md` |
| Color scheme | `useMantineColorScheme`, `useComputedColorScheme`, `ColorSchemeScript` | `theming-color-schemes.md` |
| Style props / CSS | style props, `Component.module.css` + CSS vars | `styles-style-props.md`, `styles-css-modules.md` |

## Project patterns

- **Data fetching:** React Query + the shared axios client `@/lib/api` (base `/api/v1`, bearer token).
  Deployment status is polled (SPA has no websockets on cPanel) — use `refetchInterval` while running.
- **Router links:** `<NavLink component={Link} to="..." active={...} />`; `<Anchor component={Link}>`.
- **Icons:** `import { IconRocket } from '@tabler/icons-react'` then `<IconRocket size={18} stroke={1.5} />`.
- **Responsive:** props accept objects keyed by breakpoint, e.g. `cols={{ base: 1, sm: 2, lg: 4 }}`.
- **Verify:** `pnpm --filter @cporter/web build && pnpm --filter @cporter/web lint`.
