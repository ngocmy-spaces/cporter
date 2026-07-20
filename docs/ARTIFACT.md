# Artifact & packaging

How to build the `.zip` you hand to cPorter — the contract, then a copy-paste recipe per
project type. This mirrors the in-app guide at
[`/docs/artifact`](https://cporter.ngocmy.io.vn/docs/artifact).

cPorter builds nothing itself. Your CI produces an artifact `.zip`; the
[CLI](../packages/cli), [GitHub Action](../packages/github-action), or
[HTTP API](API.md) uploads it. This page defines what that zip must contain.

## The contract

- **The zip root becomes the release root.** cPorter extracts your zip _verbatim_ into
  `releases/<id>/` — there is **no** top-level folder stripping. Do **not** wrap everything in a
  single parent folder (e.g. `myapp/…`) unless you also set that folder as the docroot.
- **The webserver serves `current/<docroot_subpath>`.** Whatever must be web-served has to sit at
  `docroot_subpath` inside the zip — `public/` for Laravel, the zip root itself for a static site.
  Set `docroot_subpath` on the project to match.
- **Size & count limits:** ≤ 256 MB per single upload (larger uploads chunk automatically),
  ≤ 50,000 files, ≤ 1 GB uncompressed. Don't ship `.git/`, editor junk, or test fixtures.
- **cPorter never touches your vhost.** You point cPanel's Document Root at
  `…/current/<docroot_subpath>` once — see [DEPLOYMENT-CPANEL.md](DEPLOYMENT-CPANEL.md).

## Dependencies: `vendor/` and `node_modules/`

Two ways to get server-side dependencies in place. Pick based on whether your host allows shell
execution.

**Option A — run a hook on the host (preferred when a shell is available).** A project can run
shell commands at two phases — `pre_activate` (before the release goes live) and `post_activate`
(after). They run verbatim in the release directory, so `composer install`, `npm ci`, and
`php artisan migrate` all work. Configure them on the project in the cPorter UI:

```yaml
# Project → hooks (run verbatim in the release dir, 600s timeout, cron worker only)
pre_activate:
  - composer install --no-dev --optimize-autoloader
post_activate:
  - php artisan migrate --force
  - php artisan config:cache
```

Hooks only run if the host exposes a shell and the binary is detected. cPorter probes `php`,
`composer`, `node`, `npm`, and `python3` in the cron shell — check **Admin → System** to see
what's available on your host. If the shell is unavailable (`proc_open`/`exec` disabled) a project
that has hooks fails the deploy with a "run manually" message.

**Option B — bundle them into the zip (the reliable fallback).** Many cPanel plans block shell
execution entirely. There, install dependencies **in CI** and ship them inside the artifact — no
hooks needed. That's what the recipes below do with `composer install --no-dev` /
`npm ci --omit=dev` before zipping.

> ⚠️ **Inode limit.** `node_modules/` can be tens of thousands of files — only bundle it for a
> **running Node app**. Static/SPA sites ship the _built output_ (e.g. `dist/`), never
> `node_modules/`.

## Configuration & secrets (`.env`)

Don't bake secrets into the artifact. cPorter can manage your environment and render it into a
shared `.env` that persists across releases — or you can ship a `.env` to seed it once. Both paths
are covered in [ENVIRONMENT.md](ENVIRONMENT.md).

## Layout by project type

There are **no** automatic per-type defaults — set `docroot_subpath` and `shared_paths` on the
project to match how you zip. Recommended conventions:

| Type        | `docroot_subpath`   | At the zip root                                   | Recommended `shared_paths`                          |
| ----------- | ------------------- | ------------------------------------------------- | --------------------------------------------------- |
| `static`    | (empty)             | `index.html` + assets                             | —                                                   |
| `laravel`   | `public`            | app root (`public/`, `vendor/`, `artisan`…)       | `.env` (file), `storage` (dir)                      |
| `php`       | `public` or (empty) | your front-controller / index                     | `.env` (file), uploads dir                          |
| `node`      | per Passenger       | built app + `node_modules/` + entrypoint          | `.env` (file)                                       |
| `wordpress` | (empty)             | `index.php` + `wp-content/`                       | `wp-config.php` (file), `wp-content/uploads` (dir)  |

Anything listed in `shared_paths` is symlinked from `shared/` into every release, so it survives
deploys and rollbacks. On the first deploy a copy shipped in the zip _seeds_ the shared location;
after that the shared copy always wins and the zipped one is discarded. A `file`-type shared path
that doesn't exist yet must be created on the server first (cPorter won't fabricate a secret).

## Full recipes

Drop-in `.github/workflows/deploy.yml` per type. Store `CPORTER_HOST` and `CPORTER_TOKEN` as
repository secrets first (see the [GitHub Action](../packages/github-action/README.md)).

### Static site / SPA

Ship the build output only. `index.html` lands at the release root; leave `docroot_subpath` empty.

```yaml
name: Deploy
on:
  push:
    branches: [main]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 20 }
      - run: npm ci && npm run build      # → produces ./dist

      # index.html must sit at the ZIP ROOT (docroot_subpath is empty for static).
      - name: Package artifact
        run: cd dist && zip -r ../out.zip .

      - uses: ngocmy-spaces/cporter/packages/github-action@v1
        with:
          host: ${{ secrets.CPORTER_HOST }}
          token: ${{ secrets.CPORTER_TOKEN }}
          project: my-site
          artifact: ./out.zip
          version: ${{ github.sha }}
```

### Laravel / PHP

Build assets, install `vendor/` in CI, zip the app root with `public/` at the top. Set
`docroot_subpath = public`. Manage `.env` via cPorter (excluded here). Migrations belong in a
`post_activate` hook if your host has a shell.

```yaml
name: Deploy
on:
  push:
    branches: [main]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 20 }
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          tools: composer:v2

      - run: npm ci && npm run build      # build FE into Laravel's public/ (adjust to your setup)
      - run: composer install --no-dev --optimize-autoloader --no-interaction

      # public/ must be at the ZIP ROOT + set docroot_subpath = public.
      # Exclude .env (managed by cPorter), .git, tests, and local storage state.
      - name: Package artifact
        run: |
          zip -r out.zip . \
            -x '.git/*' '.env' 'tests/*' 'node_modules/*' \
               'storage/logs/*' 'storage/framework/cache/*' \
               'storage/framework/sessions/*' 'storage/framework/views/*'

      - uses: ngocmy-spaces/cporter/packages/github-action@v1
        with:
          host: ${{ secrets.CPORTER_HOST }}
          token: ${{ secrets.CPORTER_TOKEN }}
          project: my-api
          artifact: ./out.zip
          version: ${{ github.sha }}
```

### Node app

Requires Node (Passenger) on the host. Bundle `node_modules/` (prod-only) unless a hook can run
`npm ci` server-side. Point `docroot_subpath` at your Passenger entrypoint.

```yaml
name: Deploy
on:
  push:
    branches: [main]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with: { node-version: 20 }
      - run: npm ci && npm run build      # → compiled output (e.g. ./dist)
      - run: npm ci --omit=dev            # production deps only

      # Ship the built app + node_modules; the app root is the ZIP ROOT.
      - name: Package artifact
        run: zip -r out.zip . -x '.git/*' '.env' 'src/*'

      - uses: ngocmy-spaces/cporter/packages/github-action@v1
        with:
          host: ${{ secrets.CPORTER_HOST }}
          token: ${{ secrets.CPORTER_TOKEN }}
          project: my-node-app
          artifact: ./out.zip
          version: ${{ github.sha }}
```

### WordPress

Serve from the site root. Keep `wp-config.php` and `wp-content/uploads` as shared paths so secrets
and media persist across releases — don't ship them in the zip.

```yaml
name: Deploy
on:
  push:
    branches: [main]
jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with: { php-version: '8.3', tools: composer:v2 }

      - run: composer install --no-dev --optimize-autoloader --no-interaction  # if you use Composer

      # index.php + wp-content at the ZIP ROOT (docroot_subpath empty).
      - name: Package artifact
        run: zip -r out.zip . -x '.git/*' 'wp-content/uploads/*' 'wp-config.php'

      - uses: ngocmy-spaces/cporter/packages/github-action@v1
        with:
          host: ${{ secrets.CPORTER_HOST }}
          token: ${{ secrets.CPORTER_TOKEN }}
          project: my-blog
          artifact: ./out.zip
          version: ${{ github.sha }}
```

## See also

- [GitHub Action](../packages/github-action/README.md) — wire the artifact into CI.
- [CLI quickstart](../packages/cli/README.md) — deploy the same zip by hand.
- [Environment variables](ENVIRONMENT.md) — what to (not) ship for `.env`.
- [SPEC.md](SPEC.md) §5–8 — the deploy pipeline internals.
