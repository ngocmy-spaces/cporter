import { openAsBlob } from 'node:fs';
import { open, stat, type FileHandle } from 'node:fs/promises';
import { basename } from 'node:path';

import { CporterApiError, DeploymentTimeoutError } from './errors.js';
import { sha256File } from './hash.js';
import { isTerminal, type Deployment, type WhoAmI } from './types.js';

/** Default: artifacts larger than this are uploaded in chunks instead of one request. */
const DEFAULT_CHUNK_THRESHOLD_BYTES = 100 * 1024 * 1024; // 100 MB
/** Default size of each raw chunk in a chunked upload. */
const DEFAULT_CHUNK_SIZE_BYTES = 8 * 1024 * 1024; // 8 MB

export interface CporterClientOptions {
  /** Base origin of the cPorter instance, e.g. `https://deploy.example.com`. */
  host: string;
  /** API key (`cpk_…`) — sent as a Bearer token. */
  token: string;
  /** API path prefix. Defaults to `/api/v1`. */
  apiPrefix?: string;
  /** Injectable fetch (for testing / custom agents). Defaults to global `fetch`. */
  fetch?: typeof fetch;
}

export type UploadPhase = 'hashing' | 'uploading' | 'chunk' | 'complete';

export interface UploadProgress {
  phase: UploadPhase;
  /** Bytes uploaded so far (chunked uploads only). */
  uploadedBytes?: number;
  totalBytes?: number;
  /** 0-based index of the chunk just sent (chunked uploads only). */
  chunkIndex?: number;
  chunkCount?: number;
}

export interface DeployOptions {
  /** Path to the artifact `.zip` on disk. */
  artifactPath: string;
  /** Optional human version label; the server falls back to the generated release id. */
  version?: string;
  /** Precomputed lowercase-hex SHA-256. Computed from the file when omitted. */
  sha256?: string;
  /**
   * Idempotency key — replaying the same key returns the existing deployment (HTTP 200)
   * instead of creating a new one. Defaults to the artifact's SHA-256.
   */
  idempotencyKey?: string;
  /** Force chunked upload regardless of size. */
  chunked?: boolean;
  /** Size above which chunked upload is used automatically. */
  chunkThresholdBytes?: number;
  /** Chunk size for chunked uploads. */
  chunkSizeBytes?: number;
  onProgress?: (progress: UploadProgress) => void;
  signal?: AbortSignal;
}

export interface WaitOptions {
  /** Poll interval in ms. Default 3000. */
  intervalMs?: number;
  /** Give up after this many ms. Default 600000 (10 min). */
  timeoutMs?: number;
  /** Called on each poll with the latest deployment. */
  onUpdate?: (deployment: Deployment) => void;
  signal?: AbortSignal;
}

export interface RollbackOptions {
  /** Target release id. Omit to roll back to the previous release. */
  releaseId?: number;
  signal?: AbortSignal;
}

function sleep(ms: number, signal?: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    if (signal?.aborted) {
      reject(signal.reason ?? new Error('Aborted'));
      return;
    }
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort);
      resolve();
    }, ms);
    const onAbort = () => {
      clearTimeout(timer);
      reject(signal?.reason ?? new Error('Aborted'));
    };
    signal?.addEventListener('abort', onAbort, { once: true });
  });
}

/**
 * Client for the cPorter deploy API. Wraps authentication, artifact upload (single or
 * chunked), status polling, and rollback so callers never touch the raw HTTP contract.
 *
 * @example
 * const client = new CporterClient({ host: 'https://deploy.example.com', token: 'cpk_…' });
 * const dep = await client.deploy('my-site', { artifactPath: './out.zip' });
 * const done = await client.waitForDeployment('my-site', dep.id);
 */
export class CporterClient {
  private readonly baseUrl: string;
  private readonly token: string;
  private readonly fetchImpl: typeof fetch;

  constructor(options: CporterClientOptions) {
    if (!options.host) throw new Error('CporterClient: `host` is required.');
    if (!options.token) throw new Error('CporterClient: `token` is required.');
    const host = options.host.replace(/\/+$/, '');
    const prefix = (options.apiPrefix ?? '/api/v1').replace(/\/+$/, '');
    this.baseUrl = `${host}${prefix}`;
    this.token = options.token;
    this.fetchImpl = options.fetch ?? globalThis.fetch;
    if (typeof this.fetchImpl !== 'function') {
      throw new Error('CporterClient: no global `fetch` available (Node >= 20 required).');
    }
  }

  /** Verify the token and inspect its scopes / project binding. */
  async whoami(signal?: AbortSignal): Promise<WhoAmI> {
    return this.request<WhoAmI>('GET', '/whoami', { signal });
  }

  /**
   * Upload an artifact and create a deployment. Automatically picks single-request or
   * chunked upload based on file size (override with `chunked` / `chunkThresholdBytes`).
   * Returns the created deployment (still `queued`/`running`) — poll with
   * {@link waitForDeployment} to await the terminal status.
   */
  async deploy(project: string, options: DeployOptions): Promise<Deployment> {
    const { size } = await stat(options.artifactPath);

    options.onProgress?.({ phase: 'hashing', totalBytes: size });
    const sha256 = options.sha256 ?? (await sha256File(options.artifactPath));
    const idempotencyKey = options.idempotencyKey ?? sha256;

    const threshold = options.chunkThresholdBytes ?? DEFAULT_CHUNK_THRESHOLD_BYTES;
    const useChunked = options.chunked ?? size > threshold;

    return useChunked
      ? this.deployChunked(project, options, sha256, idempotencyKey, size)
      : this.deploySingle(project, options, sha256, idempotencyKey, size);
  }

  private async deploySingle(
    project: string,
    options: DeployOptions,
    sha256: string,
    idempotencyKey: string,
    size: number,
  ): Promise<Deployment> {
    options.onProgress?.({ phase: 'uploading', totalBytes: size, uploadedBytes: 0 });

    const blob = await openAsBlob(options.artifactPath);
    const form = new FormData();
    form.append('artifact', blob, basename(options.artifactPath));
    form.append('sha256', sha256);
    if (options.version) form.append('version', options.version);

    const deployment = await this.request<Deployment>(
      'POST',
      `/projects/${encodeURIComponent(project)}/deployments`,
      { body: form, headers: { 'Idempotency-Key': idempotencyKey }, signal: options.signal },
    );
    options.onProgress?.({ phase: 'complete', totalBytes: size, uploadedBytes: size });
    return deployment;
  }

  private async deployChunked(
    project: string,
    options: DeployOptions,
    sha256: string,
    idempotencyKey: string,
    size: number,
  ): Promise<Deployment> {
    const chunkSize = options.chunkSizeBytes ?? DEFAULT_CHUNK_SIZE_BYTES;
    const chunkCount = Math.max(1, Math.ceil(size / chunkSize));
    const base = `/projects/${encodeURIComponent(project)}/artifacts/uploads`;

    const { upload_id: uploadId } = await this.request<{ upload_id: string }>('POST', base, {
      signal: options.signal,
    });

    let handle: FileHandle | undefined;
    try {
      handle = await open(options.artifactPath, 'r');
      const buffer = Buffer.allocUnsafe(chunkSize);
      let uploadedBytes = 0;
      for (let index = 0; index < chunkCount; index++) {
        const { bytesRead } = await handle.read(buffer, 0, chunkSize, index * chunkSize);
        // Copy the exact slice — the shared buffer may be larger than the last chunk.
        const chunk = Uint8Array.prototype.slice.call(buffer, 0, bytesRead);
        await this.request<{ received: number }>(
          'PUT',
          `${base}/${uploadId}/chunks/${index}`,
          {
            body: chunk,
            headers: { 'Content-Type': 'application/octet-stream' },
            signal: options.signal,
          },
        );
        uploadedBytes += bytesRead;
        options.onProgress?.({
          phase: 'chunk',
          chunkIndex: index,
          chunkCount,
          uploadedBytes,
          totalBytes: size,
        });
      }
    } finally {
      await handle?.close();
    }

    const body: Record<string, string> = { sha256 };
    if (options.version) body.version = options.version;
    const deployment = await this.request<Deployment>('POST', `${base}/${uploadId}/complete`, {
      body: JSON.stringify(body),
      headers: { 'Content-Type': 'application/json', 'Idempotency-Key': idempotencyKey },
      signal: options.signal,
    });
    options.onProgress?.({ phase: 'complete', totalBytes: size, uploadedBytes: size });
    return deployment;
  }

  /** Fetch a single deployment's current state. */
  async getDeployment(project: string, deploymentId: number, signal?: AbortSignal): Promise<Deployment> {
    return this.request<Deployment>(
      'GET',
      `/projects/${encodeURIComponent(project)}/deployments/${deploymentId}`,
      { signal },
    );
  }

  /**
   * Poll a deployment until it reaches a terminal status (`success`/`failed`/`rolled_back`)
   * or the timeout elapses. Returns the terminal deployment — inspect `.status` to tell
   * success from failure; throws {@link DeploymentTimeoutError} on timeout.
   */
  async waitForDeployment(
    project: string,
    deploymentId: number,
    options: WaitOptions = {},
  ): Promise<Deployment> {
    const intervalMs = options.intervalMs ?? 3000;
    const timeoutMs = options.timeoutMs ?? 600_000;
    const deadline = Date.now() + timeoutMs;

    let latest = await this.getDeployment(project, deploymentId, options.signal);
    options.onUpdate?.(latest);

    while (!isTerminal(latest.status)) {
      if (Date.now() >= deadline) {
        throw new DeploymentTimeoutError(deploymentId, latest.status, timeoutMs);
      }
      await sleep(intervalMs, options.signal);
      latest = await this.getDeployment(project, deploymentId, options.signal);
      options.onUpdate?.(latest);
    }
    return latest;
  }

  /** Convenience: deploy and wait for the result in one call. */
  async deployAndWait(
    project: string,
    options: DeployOptions & { wait?: WaitOptions },
  ): Promise<Deployment> {
    const created = await this.deploy(project, options);
    return this.waitForDeployment(project, created.id, options.wait);
  }

  /** Roll the project back to the previous (or a specified) release. */
  async rollback(project: string, options: RollbackOptions = {}): Promise<Deployment> {
    const body: Record<string, number> = {};
    if (options.releaseId != null) body.release_id = options.releaseId;
    return this.request<Deployment>('POST', `/projects/${encodeURIComponent(project)}/rollback`, {
      body: JSON.stringify(body),
      headers: { 'Content-Type': 'application/json' },
      signal: options.signal,
    });
  }

  /** Low-level request helper: attaches auth, parses `{ data }`, throws on non-2xx. */
  private async request<T>(
    method: string,
    path: string,
    init: { body?: FormData | Uint8Array | string; headers?: Record<string, string>; signal?: AbortSignal } = {},
  ): Promise<T> {
    const url = `${this.baseUrl}${path}`;
    const response = await this.fetchImpl(url, {
      method,
      headers: {
        Authorization: `Bearer ${this.token}`,
        Accept: 'application/json',
        ...init.headers,
      },
      body: init.body,
      signal: init.signal,
    });

    const text = await response.text();
    const parsed: unknown = text ? safeJsonParse(text) : undefined;

    if (!response.ok) {
      throw new CporterApiError(response.status, parsed ?? text, `${method} ${path}`);
    }

    // The API wraps successful payloads in `{ data: ... }`.
    if (parsed && typeof parsed === 'object' && 'data' in parsed) {
      return (parsed as { data: T }).data;
    }
    return parsed as T;
  }
}

function safeJsonParse(text: string): unknown {
  try {
    return JSON.parse(text);
  } catch {
    return text;
  }
}
