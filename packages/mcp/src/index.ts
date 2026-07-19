#!/usr/bin/env node
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { CporterClient, CporterApiError, isSuccess, type Deployment } from '@cporter/sdk';
import { z } from 'zod';

/**
 * cPorter MCP server — exposes deploy/status/rollback/whoami as tools an AI agent can call.
 * Configuration comes from the environment (host is non-secret; token must never be a tool
 * argument the model can echo):
 *   CPORTER_HOST      base URL, e.g. https://deploy.example.com   (required)
 *   CPORTER_TOKEN     API key (cpk_…)                              (required)
 *   CPORTER_PROJECT   default project slug                         (optional)
 */

const HOST = process.env.CPORTER_HOST;
const TOKEN = process.env.CPORTER_TOKEN;
const DEFAULT_PROJECT = process.env.CPORTER_PROJECT;

if (!HOST || !TOKEN) {
  process.stderr.write(
    'cporter-mcp: CPORTER_HOST and CPORTER_TOKEN environment variables are required.\n',
  );
  process.exit(1);
}

const client = new CporterClient({ host: HOST, token: TOKEN });

type ToolResult = {
  content: Array<{ type: 'text'; text: string }>;
  isError?: boolean;
};

function ok(data: unknown): ToolResult {
  return { content: [{ type: 'text', text: JSON.stringify(data, null, 2) }] };
}

function errorResult(err: unknown): ToolResult {
  const message =
    err instanceof CporterApiError
      ? `cPorter API ${err.status}: ${err.apiError ?? err.message}`
      : err instanceof Error
        ? err.message
        : String(err);
  return { content: [{ type: 'text', text: `Error: ${message}` }], isError: true };
}

/** Resolve a project slug from the tool arg or the CPORTER_PROJECT default. */
function resolveProject(project?: string): string {
  const slug = project ?? DEFAULT_PROJECT;
  if (!slug) {
    throw new Error('No project specified and CPORTER_PROJECT is not set.');
  }
  return slug;
}

/** Trim a deployment down to the fields an agent needs (keeps responses small). */
function summarize(d: Deployment) {
  return {
    id: d.id,
    status: d.status,
    succeeded: isSuccess(d.status),
    trigger: d.trigger,
    actor: d.actor,
    release: d.release ? { id: d.release.id, version: d.release.version, state: d.release.state } : null,
    started_at: d.started_at,
    finished_at: d.finished_at,
  };
}

const server = new McpServer({ name: 'cporter', version: '0.1.0' });

server.registerTool(
  'cporter_whoami',
  {
    title: 'cPorter: identify API key',
    description:
      'Verify the configured cPorter API key and return its name, scopes, and project binding. Use this first to confirm access.',
    inputSchema: {},
  },
  async () => {
    try {
      return ok(await client.whoami());
    } catch (err) {
      return errorResult(err);
    }
  },
);

server.registerTool(
  'cporter_deploy',
  {
    title: 'cPorter: deploy an artifact',
    description:
      'Upload a built artifact (.zip) to cPorter and deploy it. By default waits for the deployment to finish and reports the terminal status. The artifact path must be a local file on this machine.',
    inputSchema: {
      artifactPath: z.string().describe('Absolute path to the built artifact .zip on disk.'),
      project: z
        .string()
        .optional()
        .describe('Project slug. Defaults to CPORTER_PROJECT if set.'),
      version: z.string().optional().describe('Optional version label for the release.'),
      wait: z
        .boolean()
        .optional()
        .describe('Wait for the deployment to finish (default true).'),
    },
  },
  async ({ artifactPath, project, version, wait }) => {
    try {
      const slug = resolveProject(project);
      const created = await client.deploy(slug, { artifactPath, version });
      if (wait === false) {
        return ok({ ...summarize(created), waited: false });
      }
      const done = await client.waitForDeployment(slug, created.id);
      return ok({ ...summarize(done), waited: true });
    } catch (err) {
      return errorResult(err);
    }
  },
);

server.registerTool(
  'cporter_status',
  {
    title: 'cPorter: deployment status',
    description:
      'Get the current status of a deployment by id. Optionally wait until it reaches a terminal status.',
    inputSchema: {
      deploymentId: z.number().int().describe('The deployment id to inspect.'),
      project: z.string().optional().describe('Project slug. Defaults to CPORTER_PROJECT.'),
      wait: z.boolean().optional().describe('Wait for a terminal status (default false).'),
    },
  },
  async ({ deploymentId, project, wait }) => {
    try {
      const slug = resolveProject(project);
      const deployment = wait
        ? await client.waitForDeployment(slug, deploymentId)
        : await client.getDeployment(slug, deploymentId);
      return ok(summarize(deployment));
    } catch (err) {
      return errorResult(err);
    }
  },
);

server.registerTool(
  'cporter_rollback',
  {
    title: 'cPorter: roll back a release',
    description:
      'Roll a project back to its previous release, or to a specific release id. This re-points the live code — use deliberately.',
    inputSchema: {
      project: z.string().optional().describe('Project slug. Defaults to CPORTER_PROJECT.'),
      releaseId: z
        .number()
        .int()
        .optional()
        .describe('Target release id. Omit to roll back to the previous release.'),
    },
  },
  async ({ project, releaseId }) => {
    try {
      const slug = resolveProject(project);
      return ok(summarize(await client.rollback(slug, { releaseId })));
    } catch (err) {
      return errorResult(err);
    }
  },
);

const transport = new StdioServerTransport();
await server.connect(transport);
process.stderr.write('cporter-mcp: ready (stdio)\n');
