/**
 * Error raised when the API returns a non-2xx response. Carries the HTTP status and the
 * parsed body so callers can branch on, e.g., a 422 hash mismatch (`expected`/`actual`)
 * or a 403 project-scope denial.
 */
export class CporterApiError extends Error {
  readonly status: number;
  readonly body: unknown;
  /** Convenience: the `error` field the API puts on failures, when present. */
  readonly apiError: string | undefined;

  constructor(status: number, body: unknown, requestSummary: string) {
    const apiError =
      body && typeof body === 'object' && 'error' in body && typeof (body as { error: unknown }).error === 'string'
        ? (body as { error: string }).error
        : undefined;
    super(`cPorter API ${status} on ${requestSummary}${apiError ? `: ${apiError}` : ''}`);
    this.name = 'CporterApiError';
    this.status = status;
    this.body = body;
    this.apiError = apiError;
  }
}

/** Raised when a deployment does not reach a terminal status within the poll timeout. */
export class DeploymentTimeoutError extends Error {
  readonly deploymentId: number;
  readonly lastStatus: string;

  constructor(deploymentId: number, lastStatus: string, timeoutMs: number) {
    super(
      `Deployment #${deploymentId} did not finish within ${timeoutMs}ms (last status: ${lastStatus}).`,
    );
    this.name = 'DeploymentTimeoutError';
    this.deploymentId = deploymentId;
    this.lastStatus = lastStatus;
  }
}
