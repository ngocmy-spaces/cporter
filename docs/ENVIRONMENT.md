# Environment variables

How cPorter provisions your app's `.env` — managed for you, or seeded from the artifact. This
mirrors the in-app guide at [`/docs/env`](https://cporter.ngocmy.io.vn/docs/env).

## Where `.env` lives

Your `.env` is a **shared file**: it lives once at `<base_path>/shared/.env` and is symlinked into
every release as `.env` at the release root. That's why it survives deploys and rollbacks — the
artifact never contains the real secrets. You have two ways to populate it.

## Option A — let cPorter manage it (recommended)

Set your variables on the project (**Project → Environment** in the UI, or the API below). They are
stored **encrypted at rest** and rendered into `shared/.env` on the next deploy, before the release
is linked:

```dotenv
# Managed by cPorter — do not edit; overwritten on each deploy.
APP_ENV="production"
DB_HOST="localhost"
```

- Values are encrypted with the app key; they are never returned by the project list/detail
  endpoints, and audit logs record key _names_ only, never values.
- The first line is an ownership marker. cPorter only ever overwrites a file it owns — a
  `shared/.env` you created by hand is left untouched (see below).
- Changes apply on the **next deploy**, not immediately.

```bash
# Read the current vars (admin session or admin-scoped key)
curl -H "Authorization: Bearer $CPORTER_TOKEN" \
  https://deploy.example.com/api/projects/my-api/env

# Replace them (applied on the NEXT deploy)
curl -X PUT https://deploy.example.com/api/projects/my-api/env \
  -H "Authorization: Bearer $CPORTER_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"env_vars":[{"key":"APP_ENV","value":"production"},{"key":"DB_HOST","value":"localhost"}]}'
```

> 🔒 The env endpoints are **admin-only** — a deploy-scoped API key (the one CI uses) cannot read or
> write them. This keeps secrets out of your CI token's blast radius.

## Option B — seed it from the artifact

If you'd rather manage `.env` yourself, include one in the zip and list `.env` as a `file`-type
entry in the project's `shared_paths`. On the **first** deploy the bundled `.env` is moved into
`shared/` to seed it. On every deploy after that, the shared copy wins and the zipped one is
discarded — so editing it means editing `shared/.env` on the server.

> ⚠️ **Don't mix both approaches.** If cPorter is managing env vars, it writes `shared/.env`
> _before_ the artifact is linked, so a `.env` you also ship in the zip is ignored. Pick one source
> of truth per project.

## Ownership & taking over an existing file

| State of `shared/.env`                     | What a managed deploy does                                             |
| ------------------------------------------ | ---------------------------------------------------------------------- |
| Absent                                     | Written from your managed vars (marker added).                         |
| Present, has the cPorter marker            | Overwritten from your managed vars.                                    |
| Present, **no** marker (hand-created)      | Left untouched; the deploy records a warning. Use **Adopt** to take over. |

To hand cPorter control of an unmanaged file, use **Adopt** in the Environment panel (or
`POST /projects/{slug}/env/adopt`): it force-writes `shared/.env` from your current managed vars and
stamps the marker, so subsequent deploys keep it in sync.

## See also

- [Artifact & packaging](ARTIFACT.md) — what to (not) ship in the zip.
- [API reference](API.md) — the full env contract (`GET`/`PUT /projects/{slug}/env`).
