/**
 * Wire types mirroring the cPorter API contract (apps/api, docs/SPEC.md §5–§7).
 * The API wraps every payload in `{ "data": ... }`; these describe the unwrapped `data`.
 */

/** Deployment pipeline lifecycle (App\Enums\DeploymentStatus). */
export type DeploymentStatus =
  | 'queued'
  | 'running'
  | 'hooks_pending'
  | 'success'
  | 'failed'
  | 'rolled_back';

/** How a deployment was triggered (App\Enums\DeploymentTrigger). */
export type DeploymentTrigger = 'api' | 'webhook' | 'manual';

/** Terminal statuses — polling stops once a deployment reaches one of these. */
export const TERMINAL_STATUSES = ['success', 'failed', 'rolled_back'] as const;

/** True once the deployment has stopped progressing (mirrors DeploymentStatus::isTerminal). */
export function isTerminal(status: DeploymentStatus): boolean {
  return (TERMINAL_STATUSES as readonly string[]).includes(status);
}

/** True only when the deployment finished successfully. */
export function isSuccess(status: DeploymentStatus): boolean {
  return status === 'success';
}

export interface Release {
  id: number;
  project_id: number;
  artifact_id: number | null;
  version: string;
  path: string;
  state: string;
  created_at?: string | null;
  updated_at?: string | null;
}

/** One pipeline step as recorded on the deployment (shape is engine-defined; kept loose). */
export interface DeploymentStep {
  name?: string;
  status?: string;
  [key: string]: unknown;
}

export interface Deployment {
  id: number;
  project_id: number;
  release_id: number | null;
  trigger: DeploymentTrigger;
  status: DeploymentStatus;
  steps: DeploymentStep[] | null;
  actor: string | null;
  idempotency_key: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at?: string | null;
  updated_at?: string | null;
  /** Loaded relation (the API returns `->load('release')`). */
  release?: Release | null;
}

/** Response of `GET /whoami` — lets a client verify its token before deploying. */
export interface WhoAmI {
  name: string;
  scopes: string[];
  project_id: number | null;
}
