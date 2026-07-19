# @cporter/mcp

**MCP server for cPorter** — exposes deploy / status / rollback / whoami as [Model Context
Protocol](https://modelcontextprotocol.io) tools so an AI agent (Claude, etc.) can deploy to a
cPorter host and roll back releases.

It is a thin layer over [`@cporter/sdk`](../sdk) — the same client core the CLI and GitHub Action
use — so it speaks the exact [`/api/v1` contract](../../docs/API.md).

## Install / run

Runs over **stdio**. Point your MCP client at the binary; no install step needed with `npx`:

```bash
npx -y @cporter/mcp
```

Bin name: `cporter-mcp` → `dist/index.js`.

## Configuration (environment only)

Configuration is **environment-only by design** — the token is never a tool argument, so the model
can never echo it back.

| Env var | Required | Purpose |
|---|:--:|---|
| `CPORTER_HOST` | ✅ | Base URL, e.g. `https://deploy.example.com` |
| `CPORTER_TOKEN` | ✅ | API key (`cpk_…`) — needs `deploy` + `read` (+ `rollback` to roll back) |
| `CPORTER_PROJECT` | — | Default project slug; lets tools omit `project` |

The server exits with a clear stderr message if `CPORTER_HOST` or `CPORTER_TOKEN` is missing.

### Example MCP client config

```jsonc
{
  "mcpServers": {
    "cporter": {
      "command": "npx",
      "args": ["-y", "@cporter/mcp"],
      "env": {
        "CPORTER_HOST": "https://deploy.example.com",
        "CPORTER_TOKEN": "cpk_…",
        "CPORTER_PROJECT": "my-site"
      }
    }
  }
}
```

## Tools

| Tool | Inputs | What it does |
|---|---|---|
| `cporter_whoami` | — | Verify the configured key; returns name, scopes, project binding. Use first to confirm access. |
| `cporter_deploy` | `artifactPath` (required, absolute path to a local `.zip`), `project?`, `version?`, `wait?` (default `true`) | Upload + deploy an artifact. By default waits for a terminal status and reports it. |
| `cporter_status` | `deploymentId` (required int), `project?`, `wait?` (default `false`) | Get a deployment's status; optionally wait for a terminal status. |
| `cporter_rollback` | `project?`, `releaseId?` (omit → previous release) | Re-point the live release. Use deliberately. |

Responses are **summarized** (`id`, `status`, `succeeded`, `trigger`, `actor`, `release`, timestamps) to
keep them small for the model rather than returning the full deployment object.

`project` falls back to `CPORTER_PROJECT` when omitted; if neither is set the tool errors.

## Relationship to the other packages

```
MCP tool call → @cporter/mcp → @cporter/sdk → cPorter /api/v1
```

Same SDK core as [`@cporter/cli`](../cli) and the [GitHub Action](../github-action); it does **not**
shell out to the CLI. See [docs/RELEASING.md](../../docs/RELEASING.md) for how it is published.
