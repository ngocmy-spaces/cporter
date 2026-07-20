# cPorter Deploy — GitHub Action

Deploy a built artifact to [cPorter](../../README.md) from a GitHub workflow. Thin
composite action over [`@cporter/cli`](../cli) — it builds nothing itself; point it at an
artifact `.zip` your job already produced.

> **What goes in the zip?** The layout depends on your project type (static, Laravel, Node,
> WordPress) and how you handle `vendor/`/`node_modules` and `.env`. See
> [Artifact & packaging](../../docs/ARTIFACT.md) for the contract and a copy-paste recipe per
> type, and [Environment variables](../../docs/ENVIRONMENT.md) for `.env`.

## Usage

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

      # …build steps that produce ./out.zip…

      - name: Deploy to cPorter
        uses: ngocmy-spaces/cporter/packages/github-action@v1
        with:
          host: ${{ secrets.CPORTER_HOST }}
          token: ${{ secrets.CPORTER_TOKEN }}
          project: my-site
          artifact: ./out.zip
          version: ${{ github.sha }}
```

Store `CPORTER_HOST` and `CPORTER_TOKEN` as **repository secrets** (Settings → Secrets and
variables → Actions). The token needs the `deploy` scope (and `read` to wait for status).

## Inputs

| Input             | Required | Default    | Description                                                        |
| ----------------- | -------- | ---------- | ------------------------------------------------------------------ |
| `host`            | yes      |            | cPorter base URL, e.g. `https://deploy.example.com`.               |
| `token`           | yes      |            | API key (`cpk_…`). Use a secret.                                   |
| `project`         | yes      |            | Target project slug.                                               |
| `artifact`        | yes      |            | Path to the built artifact `.zip`.                                 |
| `version`         | no       | (server)   | Version label; defaults to a server-generated release id.          |
| `wait`            | no       | `true`     | Wait for the deployment to finish. Fails the job if it fails.      |
| `timeout`         | no       | `600000`   | Max wait time (ms).                                                |
| `idempotency-key` | no       | (sha256)   | Override the idempotency key. Defaults to the artifact's SHA-256.  |
| `cli-version`     | no       | `latest`   | Version of `@cporter/cli` to run.                                  |

## Outputs

| Output            | Description                                     |
| ----------------- | ----------------------------------------------- |
| `deployment-id`   | Id of the created deployment.                   |
| `status`          | Terminal status (`success`/`failed`/…).         |
| `release-version` | The deployed release version.                   |

```yaml
      - name: Deploy to cPorter
        id: cporter
        uses: ngocmy-spaces/cporter/packages/github-action@v1
        with: { host: ${{ secrets.CPORTER_HOST }}, token: ${{ secrets.CPORTER_TOKEN }}, project: my-site, artifact: ./out.zip }
      - run: echo "Deployed ${{ steps.cporter.outputs.release-version }} (#${{ steps.cporter.outputs.deployment-id }})"
```

## Notes

- Because `wait: true` is the default, a failed deployment fails the workflow step
  (the CLI exits non-zero) — no extra assertion needed.
- The idempotency key defaults to the artifact SHA-256, so re-running the same job on the
  same build returns the existing deployment instead of creating a duplicate.
- Requires `jq` (present on GitHub-hosted runners) to expose the outputs.
