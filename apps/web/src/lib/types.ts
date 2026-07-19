/**
 * Shared API types for the cPorter Admin SPA — mirrors apps/api response shapes
 * (docs/SPEC.md §5–§9). Keep in sync with the Eloquent models/enums on the backend.
 */

export type ProjectType = 'static' | 'laravel' | 'php' | 'node' | 'wordpress';
export type ProjectStatus = 'active' | 'disabled' | 'deleting';

export type DeploymentStatus =
  | 'queued'
  | 'running'
  | 'hooks_pending'
  | 'success'
  | 'failed'
  | 'rolled_back';

export type DeploymentTrigger = 'api' | 'manual' | 'cron' | 'webhook';

export type ReleaseState = 'pending' | 'extracting' | 'ready' | 'active' | 'superseded' | 'failed';

export type ApiScope = 'read' | 'deploy' | 'rollback' | 'admin';

export type UserRole = 'admin' | 'viewer';

export interface User {
  id: number;
  name: string;
  email: string;
  role: UserRole;
  created_at: string;
}

export interface AuditLog {
  id: number;
  actor: string | null;
  action: string;
  subject_type: string | null;
  subject_id: number | null;
  meta: Record<string, unknown> | null;
  ip: string | null;
  created_at: string;
}

export interface Artifact {
  id: number;
  project_id: number;
  filename: string;
  size: number;
  sha256: string;
  storage_path: string;
  status: string;
  uploaded_at: string | null;
}

export interface SharedPath {
  path: string;
  type: 'file' | 'dir';
}

export interface Project {
  id: number;
  name: string;
  slug: string;
  base_path: string;
  type: ProjectType;
  docroot_subpath: string | null;
  php_binary: string | null;
  keep_releases: number;
  /** Live footprint in bytes: active release (`current`) + shared/. */
  disk_usage: number;
  /** Total bytes of all retained release directories (rollback history). */
  releases_disk_usage: number;
  disk_usage_status: 'idle' | 'running';
  disk_usage_started_at: string | null;
  disk_usage_calculated_at: string | null;
  health_check_url: string | null;
  shared_paths: SharedPath[];
  hooks: Record<string, unknown> | null;
  status: ProjectStatus;
  created_at: string;
}

export interface DeploymentStep {
  name: string;
  status: 'success' | 'failed';
  duration_ms: number;
  error?: string;
}

export interface Release {
  id: number;
  project_id: number;
  artifact_id: number;
  version: string;
  path: string;
  state: ReleaseState;
  activated_at: string | null;
  created_at: string;
  artifact?: Artifact;
}

export interface Deployment {
  id: number;
  project_id: number;
  release_id: number;
  trigger: DeploymentTrigger;
  status: DeploymentStatus;
  steps: DeploymentStep[] | null;
  actor: string | null;
  idempotency_key: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
  release?: Release;
  project?: Project;
}

export interface ApiKey {
  id: number;
  name: string;
  prefix: string;
  scopes: ApiScope[];
  project_id: number | null;
  last_used_at: string | null;
  expires_at: string | null;
  revoked_at: string | null;
  created_at: string;
}

export interface CapabilityLimits {
  upload_max_filesize: string;
  post_max_size: string;
  memory_limit: string;
  max_execution_time: string;
}

export interface CapabilityDisk {
  free: number | null;
  total: number | null;
}

export interface CapabilityBasePath {
  path: string;
  exists: boolean;
  writable: boolean;
}

export interface Capabilities {
  php_version: string;
  sapi: string;
  open_basedir: string | null;
  extensions: Record<string, boolean>;
  functions: Record<string, boolean>;
  symlink_runtime: boolean;
  limits: CapabilityLimits;
  disk: CapabilityDisk;
  command_driver: string | null;
  cron_token_configured: boolean;
  allowed_base_paths: CapabilityBasePath[];
}

/** Cron liveness from the heartbeat store (docs/SPEC.md §10). */
export interface CronStatus {
  /** healthy = cron is beating; down = last beat is stale; unknown = never ran. */
  state: 'healthy' | 'down' | 'unknown';
  /** A = 1-min schedule:run; B = 5-min cporter:work loop; null = never seen. */
  mode: 'A' | 'B' | null;
  age_seconds: number | null;
  last_run_at: string | null;
  host: string | null;
  passes: number | null;
}

/** Envelope every cPorter API response is wrapped in. */
export interface ApiEnvelope<T> {
  data: T;
}
