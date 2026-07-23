# Releasing & Maintaining cPorter's Public Distribution

How cPorter ships the pieces that **other repos** consume to deploy to a cPorter host. This
is the operational runbook — keep it in sync when the process changes.

## What ships, and where

| Artifact | Registry / location | Consumed as |
| --- | --- | --- |
| `@cporter/sdk` | npm (public, scope `@cporter`) | dependency of the CLI + MCP |
| `@cporter/cli` | npm (public) | `npx @cporter/cli deploy …` in any CI/shell |
| `@cporter/mcp` | **repo-only by decision (T5.6)** — not on npm | built locally / run from source as an MCP server |
| **Deploy Action** | this monorepo, `packages/github-action/` | `uses: ngocmy-spaces/cporter/packages/github-action@v1` |

The GitHub Action is a **thin composite wrapper** — it runs `npx @cporter/cli deploy`. It
builds nothing itself. So the npm packages are the real payload; the action is just ergonomics.

```
CI job → uses: …/github-action@v1 → npx @cporter/cli → SDK → cPorter HTTP API
```

---

## One-time setup (already done — recorded for recovery)

1. **npm org/scope**: the `@cporter` org exists on npmjs.com. Packages publish with
   `publishConfig.access = public` (scoped packages are private by default).
2. **Repo secret `NPM_TOKEN`**: an npm **Automation** token with publish rights to `@cporter`
   (npmjs.com → Access Tokens → Generate → Automation). Used by `.github/workflows/publish.yml`.
3. **Repo is public**: required for external repos to reference the action by subpath.

Optional: configure a **Trusted Publisher** on npmjs.com (per package → Settings) to enable
npm **provenance**. Until then the publish workflow logs `Skipped OIDC … 404` and falls back
to `NPM_TOKEN` — publish still succeeds, just without a provenance badge.

---

## Releasing the npm packages

The workflow `.github/workflows/publish.yml` publishes `@cporter/sdk` then `@cporter/cli`.

**Trigger:** push a tag matching `pkg-v*`, or run it manually (Actions → *Publish packages* →
*Run workflow*, with a `dry_run` toggle that packs without publishing).

**Steps to cut a release:**

1. Bump `version` in `packages/sdk/package.json` and/or `packages/cli/package.json`.
   > The workflow reads the version from `package.json`, **not** from the tag name. The tag is
   > only the trigger. A version already on npm is **skipped** (idempotent), so re-running is safe.
2. Commit the bump.
3. Tag and push:
   ```bash
   git tag pkg-v<x.y.z>
   git push origin pkg-v<x.y.z>
   ```
4. Watch the run: `gh run list --workflow=publish.yml`.

**Ordering matters:** the SDK publishes first because the CLI's `@cporter/sdk` dependency is
`workspace:*`, which `pnpm publish` rewrites to a **pinned** version (e.g. `0.1.0`). That pinned
SDK version must resolve on npm when an external user installs the CLI. Consequence: **whenever
the SDK version changes, re-publish the CLI too** so it points at the new SDK.

**Dry run first (recommended):** run the workflow manually with `dry_run: true` — it builds and
`pnpm pack`s both packages without publishing, so you can inspect the tarball contents.

### `@cporter/mcp` — intentionally repo-only (T5.6 decision)

**Decision (2026-07-22): `@cporter/mcp` is not published to npm.** `publish.yml` ships **only**
`@cporter/sdk` and `@cporter/cli`. The MCP server is built by `pnpm build:packages` and documented
([packages/mcp/README.md](../packages/mcp/README.md)); consumers run it from a local build/source
rather than `npx`. This keeps the published surface to the two packages external users actually
install, and avoids the SDK-version-pinning re-publish treadmill (below) for a third package.

If that decision is ever revisited, publishing it is a one-time change:
1. Ensure `packages/mcp/package.json` has `publishConfig.access = public` and a correct `@cporter/sdk`
   dependency (`workspace:*`, rewritten to a pinned version on publish — same rule as the CLI).
2. Add an `@cporter/mcp` step to `publish.yml` **after** the SDK step (it depends on the SDK, like the CLI).
3. Bump its `version`, then release under the same `pkg-v*` tag flow above. Because MCP pins the SDK
   version, **re-publish MCP whenever the SDK version changes** (same constraint as the CLI).

---

## Maintaining the GitHub Action (`@v1`)

The action lives at `packages/github-action/` and is referenced by its **monorepo subpath**.
There is intentionally **no separate `deploy-action` repo** (Option A) — nothing to mirror.

- `v1` is a **floating major tag** on the monorepo. Consumers pin `@v1` and get non-breaking updates.
- To ship a non-breaking action update, move the tag forward:
  ```bash
  git tag -f v1 <commit>     # defaults to HEAD
  git push -f origin v1
  ```
- For a **breaking** change to the action's inputs/outputs, cut a new major tag (`v2`) and leave
  `v1` where it is, so existing consumers don't break.
- Keep `action.yml` and its `README.md` in sync — the README's `uses:` examples must show
  `ngocmy-spaces/cporter/packages/github-action@v1`.

> Trade-off of Option A: the action can't be listed on the GitHub Marketplace one-click
> (Marketplace requires `action.yml` at the repo root). If Marketplace becomes a requirement,
> split the action into its own repo and mirror `action.yml` + `README.md` on each release.

---

## Consuming from another repo

Add repo secrets `CPORTER_HOST` and `CPORTER_TOKEN` (token needs the `deploy` scope, plus
`read` to wait for status), then:

```yaml
      - name: Deploy to cPorter
        uses: ngocmy-spaces/cporter/packages/github-action@v1
        with:
          host: ${{ secrets.CPORTER_HOST }}
          token: ${{ secrets.CPORTER_TOKEN }}
          project: my-site          # cPorter project slug
          artifact: ./out.zip       # a .zip your job already built
          version: ${{ github.sha }}
```

Or skip the action and call the CLI directly (reads `CPORTER_HOST` / `CPORTER_TOKEN` /
`CPORTER_PROJECT` from env):

```bash
npx @cporter/cli deploy ./out.zip --wait
```

See `packages/github-action/README.md` for the full input/output reference.

---

## Troubleshooting (issues hit in practice)

| Symptom | Cause | Fix |
| --- | --- | --- |
| `action-setup` errors: *Multiple versions of pnpm specified* | `version:` set on `pnpm/action-setup` **and** `packageManager` in `package.json` | Don't set `version:` on the action — let it read `packageManager`. |
| `ERR_UNKNOWN_BUILTIN_MODULE: node:sqlite` / *pnpm requires Node >= 22.13* | Workflow ran on Node 20; pnpm 11.12 needs Node ≥ 22.13 | Set `node-version: 22` in `actions/setup-node`. |
| `Skipped OIDC … token exchange 404` | No npm Trusted Publisher configured for provenance | Harmless — publish falls back to `NPM_TOKEN`. Configure a trusted publisher to silence it. |
| `npm view @cporter/… ` returns 404 right after publish | Registry read-path propagation delay for new package/scope | Wait ~1 min; the write succeeded (see the workflow's `✅ Published` log). |
| External consumer's `uses: …@v1` fails to resolve | Repo private, or `v1` tag missing/points at a commit without `packages/github-action/action.yml` | Ensure repo is public and `git ls-tree -r --name-only v1 packages/github-action/` lists `action.yml`. |
