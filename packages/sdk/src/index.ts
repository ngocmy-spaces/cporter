/**
 * @cporter/sdk — official TypeScript SDK for the cPorter deploy API.
 *
 * This is the shared core that the CLI, GitHub Action, and MCP server are built on.
 * @see https://github.com/ (project repo) and docs/SPEC.md for the underlying API.
 */
export { CporterClient } from './client.js';
export type {
  CporterClientOptions,
  DeployOptions,
  WaitOptions,
  RollbackOptions,
  UploadProgress,
  UploadPhase,
} from './client.js';
export { CporterApiError, DeploymentTimeoutError } from './errors.js';
export { sha256File } from './hash.js';
export {
  TERMINAL_STATUSES,
  isTerminal,
  isSuccess,
  type Deployment,
  type DeploymentStatus,
  type DeploymentTrigger,
  type DeploymentStep,
  type Release,
  type WhoAmI,
} from './types.js';
