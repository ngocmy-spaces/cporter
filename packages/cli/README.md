# @cporter/cli

Command-line client for [cPorter](../../README.md). Deploy artifacts, poll status, and roll
back from any CI or shell. Wraps [`@cporter/sdk`](../sdk); this is what the
[GitHub Action](../github-action) runs under the hood.

## Usage

No install needed:

```bash
npx @cporter/cli deploy ./out.zip --project my-site \
  --host https://deploy.example.com --token cpk_…
```

Or configure via environment and keep commands short:

```bash
export CPORTER_HOST=https://deploy.example.com
export CPORTER_TOKEN=cpk_…
export CPORTER_PROJECT=my-site

cporter deploy ./out.zip --version v1.2.3   # uploads, waits, exits non-zero on failure
cporter status 42 --wait
cporter rollback --release-id 7
cporter whoami
```

## Commands

| Command | Description |
| --- | --- |
| `deploy <artifact.zip>` | Upload and deploy. Waits by default (`--no-wait` to skip). |
| `status <deployment-id>` | Show status; `--wait` to block until terminal. |
| `rollback` | Roll back to the previous release (`--release-id <n>` for a specific one). |
| `whoami` | Show the current key's name, scopes, and project. |

## Options

Global: `--host`, `--token`, `--project` (or the `CPORTER_*` env vars), `--json`, `--help`, `--version`.

`deploy`: `--version <label>`, `--wait`/`--no-wait`, `--timeout <ms>`, `--interval <ms>`,
`--idempotency-key <k>` (defaults to the artifact SHA-256), `--chunked`.

## Exit codes

`0` success · `1` failed deployment / config error · `2` usage error · `3` API error ·
`4` wait timeout.

Human progress goes to **stderr**; with `--json` the resulting resource is printed to
**stdout** — safe to pipe into `jq`.
