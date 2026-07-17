---
name: mantine-ui
description: >-
  Build or edit ANY frontend/UI in this project (apps/web) — components, pages, layout,
  styling, theming, forms, tables, charts, dates, modals, notifications. The FE is
  Mantine v9 ONLY (no Tailwind, no other UI kit, no ad-hoc CSS frameworks). Load this
  BEFORE writing React/TSX or choosing a component so you use real Mantine components,
  props, and the authoritative docs at https://mantine.dev/llms.txt instead of guessing.
  Triggers: "add a page/form/table/modal", "style this", "dark mode", "component",
  "AppShell", "Mantine", any work under apps/web/src.
---

# Mantine UI (apps/web)

The cPorter admin SPA uses **Mantine v9** exclusively. Your job: produce idiomatic Mantine
code whose props and APIs are **verified against the official docs**, never invented.

## Golden rules

1. **Mantine only.** Use Mantine components + their props + Mantine style props
   (`p`, `m`, `c`, `bg`, `fw`, `ta`, `w`, `h`, …) and the theme. Do **not** add Tailwind,
   Bootstrap, MUI, styled-components, or hand-rolled global CSS. Component-scoped needs →
   CSS Modules (`Component.module.css`) with Mantine CSS variables.
2. **Never guess a prop or component name.** If you're not 100% sure of a component's props,
   variants, or usage, **fetch its doc first** (see below) and mirror the documented API.
3. **Theme, not inline hardcoding.** Colors/spacing/radius/fonts come from the theme
   (`apps/web/src/theme.ts`) and Mantine tokens (`indigo.5`, `md`, `sm`), not raw hex/px.
4. **Dark/light aware.** The app runs `defaultColorScheme="auto"`. Use theme colors and
   `c="dimmed"` etc. so both schemes look right; toggle via `useMantineColorScheme`.
5. **Icons:** `@tabler/icons-react` (already a dep). Import per-icon.
6. **Router integration:** use `component={Link}` (react-router) on `NavLink`/`Anchor`/`Button`.

## The authoritative source: llms.txt

Mantine publishes LLM-optimized docs. **Use them — they are the source of truth for this version.**

- **Index (start here):** `https://mantine.dev/llms.txt` — lists every section + doc URL.
- **Per-topic pages (fetch the one you need):** `https://mantine.dev/llms/<section>-<name>.md`
  where `<name>` is the **kebab-case** component/hook name.
- **Everything in one file (rarely needed, large):** `https://mantine.dev/llms-full.txt`

URL patterns by section:

| Section | URL pattern | Examples |
|---|---|---|
| Core components | `/llms/core-<name>.md` | `core-button.md`, `core-app-shell.md`, `core-table.md`, `core-modal.md` |
| Hooks | `/llms/hooks-<name>.md` | `hooks-use-disclosure.md`, `hooks-use-form.md` |
| Form | `/llms/form-<name>.md` | `form-use-form.md`, `form-validation.md` |
| Dates | `/llms/dates-<name>.md` | `dates-date-picker.md` |
| Charts | `/llms/charts-<name>.md` | `charts-line-chart.md` |
| Guides | `/llms/guides-<name>.md` | `guides-vite.md` |
| Theming | `/llms/theming-<name>.md` | `theming-theme-object.md`, `theming-colors.md` |
| Styles | `/llms/styles-<name>.md` | `styles-style-props.md`, `styles-css-modules.md` |
| Extensions (X) | `/llms/x-<name>.md` | `x-notifications.md`, `x-modals.md`, `x-carousel.md` |

### Workflow for any UI task

1. Decide which Mantine components/hooks fit (see [reference.md](reference.md) cheat-sheet).
2. For anything you don't know cold, **`WebFetch` the matching `/llms/<...>.md`** and read the
   real props/examples. Kebab-case the name (`SegmentedControl` → `core-segmented-control.md`).
3. Write the code using the documented API. Prefer style props over CSS files.
4. Verify: `pnpm --filter @cporter/web build` (tsc + vite) **and** `pnpm --filter @cporter/web lint`.

Fastest lookups (no full read) — hit the exact file:
`curl -s https://mantine.dev/llms/core-<name>.md` or `WebFetch` it with a targeted prompt.

## Project setup facts (already configured — don't redo)

See [reference.md](reference.md) for the verbatim `postcss.config.cjs`, provider/theme wiring,
CSS import order, and how to add a new Mantine sub-package (`@mantine/form`, `@mantine/dates`,
`@mantine/notifications`, `@mantine/modals`, `@mantine/charts` …).

## Definition of done

- Uses only Mantine components/props/theme; no Tailwind or stray CSS framework.
- Every non-trivial prop matches the official `/llms/*.md` doc (fetched, not guessed).
- `build` and `lint` for `@cporter/web` both pass.
- Works in both light and dark color schemes.
