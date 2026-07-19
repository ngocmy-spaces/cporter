import {
  CporterClient,
  CporterApiError,
  DeploymentTimeoutError,
  isSuccess,
  type Deployment,
} from '@cporter/sdk';

import { bool, num, parseArgs, str, type ParsedArgs } from './args.js';

const HELP = `cporter — command-line client for the cPorter deploy API

Usage:
  cporter <command> [options]

Commands:
  deploy <artifact.zip>   Upload an artifact and deploy it
  status <deployment-id>  Show a deployment's status (optionally wait for it)
  rollback                Roll back to the previous (or a given) release
  whoami                  Show the current API key's name, scopes, and project

Global options (or env var):
  --host <url>        cPorter base URL          (CPORTER_HOST)
  --token <cpk_…>     API key                   (CPORTER_TOKEN)
  --project <slug>    Target project slug       (CPORTER_PROJECT)
  --json              Emit machine-readable JSON on stdout
  --help, -h          Show this help
  --version           Show the CLI version

deploy options:
  --version <label>       Human version label (defaults to a generated release id)
  --wait / --no-wait      Wait for the deployment to finish (default: wait)
  --timeout <ms>          Max wait time            (default: 600000)
  --interval <ms>         Poll interval            (default: 3000)
  --idempotency-key <k>   Override idempotency key (default: artifact SHA-256)
  --chunked               Force chunked upload

Examples:
  cporter deploy ./out.zip --project my-site --host https://deploy.example.com
  CPORTER_HOST=… CPORTER_TOKEN=… cporter status 42 --project my-site --wait
  cporter rollback --project my-site --release-id 7
`;

/** CLI entry point. Returns a process exit code. */
export async function run(argv: string[], version: string): Promise<number> {
  const parsed = parseArgs(argv);
  const command = parsed.positionals[0];

  if (parsed.flags.version === true && !command) {
    process.stdout.write(`${version}\n`);
    return 0;
  }
  if (parsed.flags.help || parsed.flags.h || !command) {
    process.stdout.write(HELP);
    return command ? 0 : 2;
  }

  try {
    switch (command) {
      case 'deploy':
        return await deploy(parsed);
      case 'status':
        return await status(parsed);
      case 'rollback':
        return await rollback(parsed);
      case 'whoami':
        return await whoami(parsed);
      default:
        fail(`Unknown command: ${command}\n\n${HELP}`);
        return 2;
    }
  } catch (err) {
    if (err instanceof CporterApiError) {
      fail(err.message);
      return 3;
    }
    if (err instanceof DeploymentTimeoutError) {
      fail(err.message);
      return 4;
    }
    fail(err instanceof Error ? err.message : String(err));
    return 1;
  }
}

// ── Commands ────────────────────────────────────────────────────────────────

async function deploy(parsed: ParsedArgs): Promise<number> {
  const { flags } = parsed;
  const client = makeClient(flags);
  const project = requireProject(flags);
  const artifactPath = parsed.positionals[1] ?? str(flags, 'artifact');
  if (!artifactPath) {
    fail('deploy requires an artifact path: cporter deploy <artifact.zip> --project <slug>');
    return 2;
  }

  info(`Deploying ${artifactPath} to "${project}"…`);
  const created = await client.deploy(project, {
    artifactPath,
    version: str(flags, 'version'),
    idempotencyKey: str(flags, 'idempotency-key'),
    chunked: bool(flags, 'chunked', false) || undefined,
    onProgress: (p) => {
      if (p.phase === 'hashing') info('  hashing artifact…');
      else if (p.phase === 'uploading') info('  uploading…');
      else if (p.phase === 'chunk' && p.totalBytes) {
        const pct = Math.round(((p.uploadedBytes ?? 0) / p.totalBytes) * 100);
        info(`  uploading chunk ${(p.chunkIndex ?? 0) + 1}/${p.chunkCount} (${pct}%)`);
      }
    },
  });
  info(`  → deployment #${created.id} created (release ${created.release?.version ?? '?'})`);

  if (!bool(flags, 'wait', true)) {
    return output(flags, created, `Deployment #${created.id} queued.`);
  }

  const done = await client.waitForDeployment(project, created.id, {
    intervalMs: num(flags, 'interval'),
    timeoutMs: num(flags, 'timeout'),
    onUpdate: (d) => info(`  status: ${d.status}`),
  });
  return finish(flags, done);
}

async function status(parsed: ParsedArgs): Promise<number> {
  const { flags } = parsed;
  const client = makeClient(flags);
  const project = requireProject(flags);
  const id = Number(parsed.positionals[1] ?? str(flags, 'deployment'));
  if (Number.isNaN(id)) {
    fail('status requires a deployment id: cporter status <id> --project <slug>');
    return 2;
  }

  const deployment = bool(flags, 'wait', false)
    ? await client.waitForDeployment(project, id, {
        intervalMs: num(flags, 'interval'),
        timeoutMs: num(flags, 'timeout'),
        onUpdate: (d) => info(`  status: ${d.status}`),
      })
    : await client.getDeployment(project, id);

  return finish(flags, deployment);
}

async function rollback(parsed: ParsedArgs): Promise<number> {
  const { flags } = parsed;
  const client = makeClient(flags);
  const project = requireProject(flags);
  const releaseId = num(flags, 'release-id');

  info(releaseId ? `Rolling back "${project}" to release ${releaseId}…` : `Rolling back "${project}" to previous release…`);
  const deployment = await client.rollback(project, { releaseId });
  return finish(flags, deployment);
}

async function whoami(parsed: ParsedArgs): Promise<number> {
  const { flags } = parsed;
  const client = makeClient(flags);
  const who = await client.whoami();
  if (bool(flags, 'json', false)) {
    process.stdout.write(`${JSON.stringify(who, null, 2)}\n`);
  } else {
    process.stdout.write(
      `key:      ${who.name}\nscopes:   ${who.scopes.join(', ') || '(none)'}\nproject:  ${who.project_id ?? '(any)'}\n`,
    );
  }
  return 0;
}

// ── Helpers ───────────────────────────────────────────────────────────────

function makeClient(flags: Record<string, string | boolean>): CporterClient {
  const host = str(flags, 'host', 'CPORTER_HOST');
  const token = str(flags, 'token', 'CPORTER_TOKEN');
  if (!host) throw new Error('Missing --host (or CPORTER_HOST).');
  if (!token) throw new Error('Missing --token (or CPORTER_TOKEN).');
  return new CporterClient({ host, token });
}

function requireProject(flags: Record<string, string | boolean>): string {
  const project = str(flags, 'project', 'CPORTER_PROJECT');
  if (!project) throw new Error('Missing --project (or CPORTER_PROJECT).');
  return project;
}

/** Print a terminal deployment and return the appropriate exit code (0 = success). */
function finish(flags: Record<string, string | boolean>, deployment: Deployment): number {
  const ok = isSuccess(deployment.status);
  const code = output(
    flags,
    deployment,
    `${ok ? '✓' : '✗'} deployment #${deployment.id} — ${deployment.status}` +
      (deployment.release?.version ? ` (release ${deployment.release.version})` : ''),
  );
  return ok ? code : 1;
}

/** Emit either JSON (stdout) or a human summary; returns 0. */
function output(flags: Record<string, string | boolean>, data: unknown, human: string): number {
  if (bool(flags, 'json', false)) {
    process.stdout.write(`${JSON.stringify(data, null, 2)}\n`);
  } else {
    process.stdout.write(`${human}\n`);
  }
  return 0;
}

function info(message: string): void {
  process.stderr.write(`${message}\n`);
}

function fail(message: string): void {
  process.stderr.write(`error: ${message}\n`);
}
