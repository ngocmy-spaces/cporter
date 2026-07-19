# @cporter/sdk

Official TypeScript SDK for the [cPorter](../../README.md) deploy API. This is the shared
core that [`@cporter/cli`](../cli), the [GitHub Action](../github-action), and the
[MCP server](../mcp) are all built on — use it directly for programmatic deploys.

Node ≥ 20, zero runtime dependencies (uses the platform `fetch`).

## Install

```bash
pnpm add @cporter/sdk
```

## Usage

```ts
import { CporterClient, isSuccess } from '@cporter/sdk';

const client = new CporterClient({
  host: 'https://deploy.example.com',
  token: process.env.CPORTER_TOKEN!, // cpk_…
});

// Verify the key first (optional)
console.log(await client.whoami());

// Upload + deploy (auto single/chunked by size), then wait for the result
const deployment = await client.deployAndWait('my-site', {
  artifactPath: './out.zip',
  version: 'v1.2.3',
  onProgress: (p) => console.log(p.phase),
});

if (!isSuccess(deployment.status)) {
  throw new Error(`Deploy failed: ${deployment.status}`);
}

// Roll back to the previous release
await client.rollback('my-site');
```

## API

| Method | Purpose |
| --- | --- |
| `whoami()` | Verify the token; returns name, scopes, project binding. |
| `deploy(project, opts)` | Upload artifact + create deployment. Picks single vs chunked upload by size. Returns the created (non-terminal) deployment. |
| `waitForDeployment(project, id, opts)` | Poll until terminal (`success`/`failed`/`rolled_back`) or timeout. |
| `deployAndWait(project, opts)` | `deploy` + `waitForDeployment` in one call. |
| `getDeployment(project, id)` | Fetch current status. |
| `rollback(project, { releaseId? })` | Roll back to previous or a given release. |

Key behaviours:

- **Integrity**: the SHA-256 is streamed from disk and sent as `sha256`; the server rejects
  a mismatch with HTTP 422.
- **Idempotency**: `idempotencyKey` defaults to the artifact SHA-256, so retrying the same
  build returns the existing deployment instead of duplicating it.
- **Large artifacts**: files over `chunkThresholdBytes` (default 100 MB) upload in chunks
  automatically; force it with `chunked: true`.
- **Errors**: non-2xx throws `CporterApiError` (`.status`, `.apiError`, `.body`); a poll
  timeout throws `DeploymentTimeoutError`.
