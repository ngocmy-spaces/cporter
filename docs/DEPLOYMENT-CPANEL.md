# Deploying cPorter to cPanel

cPorter installs like a normal web app on one domain (`cporter.domain`) and then deploys your
*other* domains. This guide covers the **one-time manual bootstrap** (cPorter can't deploy
itself before it exists) and day-2 operation. See also [SPEC §14](SPEC.md).

Replace `USER` with your cPanel username and `cporter.domain` with your control-panel domain.

---

## 1. Prerequisites (in cPanel)

1. **Subdomain** — create `cporter.domain`. Set its **Document Root** to
   `cporter.domain/current/public` (Domains ▸ create/manage → Document Root). It'll 404 until
   the first release exists — that's fine.
2. **PHP 8.3** for that domain — *MultiPHP Manager* → set `cporter.domain` to `ea-php83`.
   Note the CLI path (usually `/opt/cpanel/ea-php83/root/usr/bin/php`, or `/usr/local/bin/php`).
   Always use this **absolute CLI path** in cron lines and hooks — bare `php` may be php-cgi
   (verify with `php -v` showing `(cli)`), which silently no-ops artisan commands.
3. **MySQL** — *MySQL® Databases*: create a database (`USER_cporter`) + user, add the user to
   the DB with **ALL PRIVILEGES**.
4. **A shell for the one-time bootstrap** — *Terminal* (cPanel jailed shell) or SSH. If you
   have neither, see [§7 No-shell bootstrap](#7-no-shell-bootstrap).

> cPorter needs no root. It manages sibling folders because cPanel runs PHP as your account
> user. On the target host, web PHP has no `exec`, so Laravel hooks run through a **cron**
> (§5) — that's why the cron is mandatory.

---

## 2. Build the artifact (`.zip`)

Do this locally or in CI. **Build with PHP 8.3** (match the host) — easiest via CI
(`.github/workflows/deploy.yml` already uses PHP 8.3), or the Docker api image, or locally:

```bash
# in the repo root
cd apps/api && composer install --no-dev --optimize-autoloader && cd ../..
pnpm install
pnpm build:artifact        # builds web → copies into apps/api/public → zips apps/api
# → build/out/cporter-<version>.zip  (+ prints sha256)
```

The zip is the Laravel app (`apps/api`) with `vendor/` and the built SPA in `public/`.

---

## 3. First install (bootstrap)

Open **Terminal**/SSH and run (adjust paths):

```bash
cd ~/cporter.domain
mkdir -p releases shared

# 3a. Upload cporter-<version>.zip here (File Manager or scp), then extract into a release:
REL=$(date +%Y%m%d_%H%M%S)
mkdir -p releases/$REL
unzip -q cporter-*.zip -d releases/$REL      # if `unzip` missing, extract via File Manager

# 3b. Shared files that persist across releases (.env + storage)
cp -r releases/$REL/storage shared/storage 2>/dev/null || true
```

Create **`~/cporter.domain/shared/.env`** (production):

```dotenv
APP_NAME=cPorter
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://cporter.domain

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=USER_cporter
DB_USERNAME=USER_cporter
DB_PASSWORD=your-db-password

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

# Jail: absolute paths cPorter may deploy into. Your sites live under /home/USER.
CPORTER_ALLOWED_BASE_PATHS=/home/USER
CPORTER_COMMAND_DRIVER=cron-worker

# First admin (created by the seeder). Change the password after first login.
CPORTER_ADMIN_EMAIL=you@example.com
CPORTER_ADMIN_PASSWORD=change-me-now

# Optional: for CI webhooks
CPORTER_WEBHOOK_SECRET=
```

Link shared into the release, generate the key, migrate + seed, then activate:

```bash
cd ~/cporter.domain
PHP=/opt/cpanel/ea-php83/root/usr/bin/php

ln -sfn ../../shared/.env      releases/$REL/.env
rm -rf releases/$REL/storage && ln -sfn ../../shared/storage releases/$REL/storage

cd releases/$REL
$PHP artisan key:generate --force
$PHP artisan migrate --force --seed
$PHP artisan config:cache

# 3c. Activate this release (atomic symlink)
cd ~/cporter.domain
ln -sfn releases/$REL current
```

Confirm the domain's Document Root is `cporter.domain/current/public` (step 1). Open
**https://cporter.domain** → log in with the admin from `.env`. ✅

---

## 4. Cron (required — runs Laravel hooks, queue, housekeeping)

Only **one** cron entry is needed. Pick the option matching your host's minimum cron cadence.

### Option A — host allows a 1-minute cron (preferred)

*cPanel ▸ Cron Jobs* → add, every minute:

```
* * * * * cd /home/USER/cporter.domain/current && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

This fans out to `cporter:run-jobs` (finalize Laravel deploys), `queue:work` (artifact
extraction), and `cporter:housekeep`. Laravel deploys finalize within ~1 minute.

### Option B — host caps cron at 5 minutes (common on cPanel)

A `schedule:run` that only fires every 5 minutes makes the `everyMinute` tasks effectively
5-minutely too, so a Laravel deploy could wait up to ~5 min to finalize. Instead, call the
internal-loop worker directly — it loops **in-process** for ~4.5 min per tick, finalizing
deploys, draining the queue, and housekeeping every ~12s, then exits before the next tick:

```
*/5 * * * * cd /home/USER/cporter.domain/current && /opt/cpanel/ea-php83/root/usr/bin/php artisan cporter:work >> /dev/null 2>&1
```

Use **either** Option A **or** Option B — not both. With `cporter:work` you do *not* also add
the `schedule:run` cron. Effective finalize latency drops back to ~seconds despite the 5-minute
cron limit. Tunables: `--duration` (loop length, keep `< 300` for a `*/5` cron), `--sleep`
(gap between passes), `--max` (deploys per pass). An atomic cache lock prevents overlapping
workers.

---

## 5. Point your other domains at their `current/`

For each site cPorter will deploy, set that domain's **Document Root**:
- Laravel: `<site>/current/public`
- Static / WordPress / plain PHP: `<site>/current`

(cPorter creates `releases/`, `shared/`, `current` inside the site folder on first deploy.)

---

## 6. Register a project and deploy

1. In cPorter UI → **Projects ▸ New project**:
   - `base_path` = `/home/USER/<site>.domain`
   - `type` = static / laravel / wordpress / php
   - Laravel: `docroot_subpath=public`, `shared_paths=[".env","storage"]`,
     hooks `pre_activate=["php artisan migrate --force","php artisan config:cache"]`,
     `post_activate=["php artisan queue:restart"]`
     (each hook is a full shell command — use an **absolute PHP CLI path**, e.g.
     `/usr/local/bin/php artisan migrate` or `/opt/cpanel/ea-php83/root/usr/bin/php artisan migrate`.
     ⚠️ Do **not** use bare `php`: on cPanel it often resolves to php-cgi, which prints artisan's help
     list and exits 0 — the hook shows as *succeeded* but the migration never ran. The **System** page lists
     detected binaries — probed in the cron shell via `command -v` by `cporter:probe-binaries` (run automatically
     by the scheduler/worker, no extra cron needed), so it reflects the exact PATH your hooks run with;
     verify it points at a CLI build, e.g. `/usr/local/bin/php -v` should say `(cli)`, not `(cgi-fcgi)`)
   - `health_check_url=https://<site>.domain/up` (Laravel has `/up`; static: any 200 URL)
2. **API Keys ▸ New key** (scope `deploy`, `read`, optionally `rollback`) → copy the token.
3. Deploy from CI or Postman:
   - **GitHub Actions**: use the cPorter Action (`uses: ngocmy-spaces/cporter/packages/github-action@v1`),
     set repo secrets `CPORTER_HOST=https://cporter.domain` + `CPORTER_TOKEN=<key>`, and pass the project
     as the `project:` input (slug). See [docs/RELEASING.md](RELEASING.md#consuming-from-another-repo).
   - **Manual/Postman**: `POST https://cporter.domain/api/v1/projects/<slug>/deployments`
     with `Authorization: Bearer <key>` + multipart `artifact` (.zip) + `sha256`. Full contract: [docs/API.md](API.md).

Laravel deploys return `202 hooks_pending`; the cron finalizes (migrate → activate → health)
within ~1 minute. Static deploys finish immediately.

---

## 7. No-shell bootstrap

No Terminal/SSH? Run the one-time setup via a **temporary cron job** instead of interactively:

```
* * * * * cd /home/USER/cporter.domain/releases/REL && /opt/cpanel/ea-php83/root/usr/bin/php artisan migrate --force --seed >> /home/USER/cporter-install.log 2>&1
```

Wait one minute, check the log, then **delete that cron**. Do `key:generate` by setting a
fixed `APP_KEY` in `shared/.env` yourself (`base64:` + 32 random bytes). Create `releases/`,
`shared/`, the symlinks, and `current` via **File Manager** (it supports creating symlinks on
most hosts; otherwise the first `schedule:run` cron gives you a shell entry to run the `ln`s).

---

## 8. Upgrading cPorter later

Once running, cPorter can deploy **itself**: register a project with
`base_path=/home/USER/cporter.domain`, `type=laravel`, `docroot_subpath=public`,
`shared_paths=[".env","storage"]`, hooks `["artisan migrate --force","artisan config:cache"]`.
Then push new artifacts to it like any other project (careful — a bad release takes the panel
down until rollback). Until you set that up, repeat §2–§3 with a new `releases/<id>` + swap
`current`.

---

## 9. Troubleshooting

| Symptom | Fix |
|---|---|
| Domain 404 / blank | Document Root must be `…/current/public`; `current` symlink must exist. |
| 500 on every page | `shared/.env` missing/misconfigured, or `APP_KEY` empty. Check `storage/logs`. |
| Deploy stuck `queued`/`hooks_pending` | The cron (§4) isn't running — verify the cron line + PHP CLI path. |
| Laravel hooks "run manually" | CLI `proc_open` disabled even in cron → run the hook commands over Terminal, or ask host to allow it. |
| `base_path must be within an allowed base path` | Add the site's parent to `CPORTER_ALLOWED_BASE_PATHS`. |
| Symlink swap fails | Host disallows symlinks → cPorter falls back to copy-swap automatically; ensure disk space. |
| Artifact upload rejected | Raise `upload_max_filesize`/`post_max_size` (MultiPHP INI Editor) or use chunked upload. |
