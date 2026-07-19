import { createHash } from 'node:crypto';
import { createReadStream } from 'node:fs';

/**
 * Compute the lowercase hex SHA-256 of a file by streaming it — never loads the whole
 * artifact into memory. This is the digest the API verifies against (`sha256` field);
 * the CLI also reuses it as the default Idempotency-Key.
 */
export function sha256File(path: string): Promise<string> {
  return new Promise((resolve, reject) => {
    const hash = createHash('sha256');
    const stream = createReadStream(path);
    stream.on('error', reject);
    stream.on('data', (chunk) => hash.update(chunk));
    stream.on('end', () => resolve(hash.digest('hex')));
  });
}
