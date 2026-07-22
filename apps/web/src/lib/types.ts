/**
 * Shared API types for the cPorter Admin SPA — mirrors apps/api response shapes
 * (docs/SPEC.md §5–§9). Keep in sync with the Eloquent models/enums on the backend.
 */

export type ProjectType = 'static' | 'laravel' | 'php' | 'node' | 'wordpress';
export type ProjectStatus = 'active' | 'disabled' | 'deleting';
/** Continuously-monitored project health (docs/SPEC.md §21.1). Only `unhealthy` raises an alert. */
export type ProjectHealthStatus = 'healthy' | 'unhealthy' | 'unknown' | 'paused';
/** Post-activation failures the auto-rollback policy can react to (docs/SPEC.md §21.2). */
export type RollbackTrigger = 'health_check' | 'post_activate_hook';

export type DeploymentStatus =
  | 'queued'
  | 'running'
  | 'hooks_pending'
  | 'success'
  | 'failed'
  | 'rolled_back';

export type DeploymentTrigger = 'api' | 'manual' | 'cron' | 'webhook';

export type ReleaseState =
  | 'pending'
  | 'extracting'
  | 'ready'
  | 'active'
  | 'superseded'
  | 'failed'
  | 'pruned';

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
  // Nulled once the zip is reclaimed after the deploy is stable (the row is kept for reporting).
  storage_path: string | null;
  status: string;
  uploaded_at: string | null;
  pruned_at: string | null;
}

export interface SharedPath {
  path: string;
  type: 'file' | 'dir';
}

/** Ordered shell commands run around release activation (docs/API.md — PATCH /projects/{slug}). */
export interface ProjectHooks {
  pre_activate?: string[];
  post_activate?: string[];
}

export interface Project {
  id: number;
  name: string;
  slug: string;
  base_path: string;
  type: ProjectType;
  docroot_subpath: string | null;
  keep_releases: number;
  /** Opt-in failures that trigger an automatic rollback; empty = disabled (docs/SPEC.md §21.2). */
  auto_rollback_on: RollbackTrigger[];
  /** Live footprint in bytes: active release (`current`) + shared/. */
  disk_usage: number;
  /** Total bytes of all retained release directories (rollback history). */
  releases_disk_usage: number;
  disk_usage_status: 'idle' | 'running';
  disk_usage_started_at: string | null;
  disk_usage_calculated_at: string | null;
  /** Per-shared-path size in bytes, keyed by the entry's relative path; null until first computed. */
  shared_disk_usage: Record<string, number> | null;
  health_check_url: string | null;
  /** Persisted health signal — the single source dashboard/alerts read (docs/SPEC.md §21.1). */
  health_status: ProjectHealthStatus;
  health_checked_at: string | null;
  health_last_ok_at: string | null;
  shared_paths: SharedPath[];
  hooks: ProjectHooks | null;
  status: ProjectStatus;
  created_at: string;
  /** Overview summaries embedded by `show` so the detail tabs can load lazily; null when none. */
  active_release?: { id: number; version: string; activated_at: string | null } | null;
  last_deployment?: { id: number; status: DeploymentStatus; created_at: string } | null;
  /** Total releases; lets the UI freeze identity fields without loading the (lazy) releases list. */
  release_count?: number;
}

export interface DeploymentStep {
  name: string;
  /** `warning` = a non-fatal step (e.g. write_env skipping an unmanaged shared/.env). */
  status: 'success' | 'failed' | 'warning';
  duration_ms: number;
  error?: string;
  note?: string;
}

/** One managed environment variable rendered into shared/.env on deploy (docs/API.md — /projects/{slug}/env). */
export interface EnvVar {
  key: string;
  value: string;
}

/** On-disk state of shared/.env, so the UI can offer take-over of a hand-created file. */
export interface EnvFileState {
  exists: boolean;
  managed: boolean;
}

/** Payload of GET/PUT/adopt on /projects/{slug}/env. */
export interface ProjectEnv {
  vars: EnvVar[];
  file: EnvFileState;
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
  /** External CLIs a deploy hook might call: name → resolved path (null = not found). */
  binaries: Record<string, string | null>;
  /** 'cron' = probed via `command -v` in the shell hooks run in (authoritative); 'path-scan' = web PATH fallback. */
  binaries_source: 'cron' | 'path-scan';
  /** When the cron-worker last probed binaries; null until it has run once. */
  binaries_detected_at: string | null;
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

/** Codes surfaced by the artifact-storage heartbeat's `warnings` array. */
export type StorageWarningCode = 'pruning_disabled' | 'store_over_threshold' | 'sweep_stale';

/** Artifact storage housekeeping liveness from the reclaim sweep (docs/SPEC.md §10). */
export interface StorageStatus {
  /** healthy = last sweep ran and store is under threshold; warning = a warnings[] condition; unknown = never swept. */
  state: 'healthy' | 'warning' | 'unknown';
  last_run_at: string | null;
  age_seconds: number | null;
  /** Total size of cPorter's artifact store on disk, in bytes. */
  store_bytes: number | null;
  /** Artifacts still holding a .zip on disk (not yet reclaimed). */
  unpruned_count: number | null;
  /** Reclaimed in the last sweep. */
  reclaimed_count: number | null;
  /** Freed in the last sweep, in bytes. */
  freed_bytes: number | null;
  projects_swept: number | null;
  prune_enabled: boolean;
  /** Threshold that triggers the `store_over_threshold` warning, in bytes. */
  warn_bytes: number;
  host: string | null;
  warnings: StorageWarningCode[];
}

export type PreflightStatus = 'ok' | 'created' | 'pending' | 'warning' | 'error' | 'manual';

export interface PreflightCheck {
  key: string;
  label: string;
  status: PreflightStatus;
  detail: string;
}

export interface PreflightReport {
  ok: boolean;
  base_path: string;
  checks: PreflightCheck[];
}

/** Envelope every cPorter API response is wrapped in. */
export interface ApiEnvelope<T> {
  data: T;
}
