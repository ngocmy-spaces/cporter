# Deploying cPorter to cPanel

cPorter installs like a normal web app on one domain (`deploy.domain`) and then deploys your
*other* domains. This guide covers the **one-time manual bootstrap** (cPorter can't deploy
itself before it exists) and day-2 operation. See also [SPEC §14](SPEC.md).

Replace `USER` with your cPanel username and `deploy.domain` with your control-panel domain.

---

## 1. Prerequisites (in cPanel)

1. **Subdomain** — create `deploy.domain`. Set its **Document Root** to
   `deploy.domain/current/public` (Domains ▸ create/manage → Document Root). It'll 404 until
   the first release exists — that's fine.
2. **PHP 8.3** for that domain — *MultiPHP Manager* → set `deploy.domain` to `ea-php83`.
   Note the CLI path (usually `/opt/cpanel/ea-php83/root/usr/bin/php`).
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
cd ~/deploy.domain
mkdir -p releases shared

# 3a. Upload cporter-<version>.zip here (File Manager or scp), then extract into a release:
REL=$(date +%Y%m%d_%H%M%S)
mkdir -p releases/$REL
unzip -q cporter-*.zip -d releases/$REL      # if `unzip` missing, extract via File Manager

# 3b. Shared files that persist across releases (.env + storage)
cp -r releases/$REL/storage shared/storage 2>/dev/null || true
```

Create **`~/deploy.domain/shared/.env`** (production):

```dotenv
APP_NAME=cPorter
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://deploy.domain

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
cd ~/deploy.domain
PHP=/opt/cpanel/ea-php83/root/usr/bin/php

ln -sfn ../../shared/.env      releases/$REL/.env
rm -rf releases/$REL/storage && ln -sfn ../../shared/storage releases/$REL/storage

cd releases/$REL
$PHP artisan key:generate --force
$PHP artisan migrate --force --seed
$PHP artisan config:cache

# 3c. Activate this release (atomic symlink)
cd ~/deploy.domain
ln -sfn releases/$REL current
```

Confirm the domain's Document Root is `deploy.domain/current/public` (step 1). Open
**https://deploy.domain** → log in with the admin from `.env`. ✅

---

## 4. Cron (required — runs Laravel hooks, queue, housekeeping)

*cPanel ▸ Cron Jobs* → add, every minute:

```
* * * * * cd /home/USER/deploy.domain/current && /opt/cpanel/ea-php83/root/usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

This fans out to `cporter:run-jobs` (finalize Laravel deploys), `queue:work` (artifact
extraction), and `cporter:housekeep`.

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
     `php_binary=/opt/cpanel/ea-php83/root/usr/bin/php`,
     hooks `pre_activate=["artisan migrate --force","artisan config:cache"]`,
     `post_activate=["artisan queue:restart"]`
   - `health_check_url=https://<site>.domain/up` (Laravel has `/up`; static: any 200 URL)
2. **API Keys ▸ New key** (scope `deploy`, `read`, optionally `rollback`) → copy the token.
3. Deploy from CI or Postman:
   - **GitHub Actions**: use the cPorter Action (`uses: ngocmy-spaces/cporter/packages/github-action@v1`),
     set repo secrets `CPORTER_HOST=https://deploy.domain` + `CPORTER_TOKEN=<key>`, and pass the project
     as the `project:` input (slug). See [docs/RELEASING.md](RELEASING.md#consuming-from-another-repo).
   - **Manual/Postman**: `POST https://deploy.domain/api/v1/projects/<slug>/deployments`
     with `Authorization: Bearer <key>` + multipart `artifact` (.zip) + `sha256`. Full contract: [docs/API.md](API.md).

Laravel deploys return `202 hooks_pending`; the cron finalizes (migrate → activate → health)
within ~1 minute. Static deploys finish immediately.

---

## 7. No-shell bootstrap

No Terminal/SSH? Run the one-time setup via a **temporary cron job** instead of interactively:

```
* * * * * cd /home/USER/deploy.domain/releases/REL && /opt/cpanel/ea-php83/root/usr/bin/php artisan migrate --force --seed >> /home/USER/cporter-install.log 2>&1
```

Wait one minute, check the log, then **delete that cron**. Do `key:generate` by setting a
fixed `APP_KEY` in `shared/.env` yourself (`base64:` + 32 random bytes). Create `releases/`,
`shared/`, the symlinks, and `current` via **File Manager** (it supports creating symlinks on
most hosts; otherwise the first `schedule:run` cron gives you a shell entry to run the `ln`s).

---

## 8. Upgrading cPorter later

Once running, cPorter can deploy **itself**: register a project with
`base_path=/home/USER/deploy.domain`, `type=laravel`, `docroot_subpath=public`,
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
