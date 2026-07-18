<?php

namespace App\Domain\Deploy;

use App\Domain\Storage\StorageException;
use Illuminate\Support\Str;

/**
 * Chunked artifact upload for artifacts larger than post_max_size (256MB) — docs/SPEC.md §6.
 * Chunks are stored under cPorter's own storage (internal, not jailed) and concatenated on
 * complete. The session id is a random uuid; indexes are validated to prevent path escape.
 */
class ArtifactUploadService
{
    public function init(): string
    {
        $id = (string) Str::uuid();
        $dir = $this->dir($id);
        if (! is_dir($dir) && ! @mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new StorageException('Failed to create upload session.');
        }

        return $id;
    }

    public function putChunk(string $uploadId, int $index, string $content): void
    {
        $dir = $this->dir($uploadId);
        if (! is_dir($dir)) {
            throw new StorageException('Unknown upload session.');
        }
        if ($index < 0 || $index > 1_000_000) {
            throw new StorageException('Invalid chunk index.');
        }
        if (file_put_contents($dir.'/'.$index.'.part', $content) === false) {
            throw new StorageException('Failed to store chunk.');
        }
    }

    /** Concatenate all chunks (numeric order) into a single .zip; returns its path. */
    public function assemble(string $uploadId): string
    {
        $dir = $this->dir($uploadId);
        if (! is_dir($dir)) {
            throw new StorageException('Unknown upload session.');
        }

        $parts = glob($dir.'/*.part') ?: [];
        usort($parts, fn ($a, $b) => (int) basename($a, '.part') <=> (int) basename($b, '.part'));

        if ($parts === []) {
            throw new StorageException('No chunks were uploaded.');
        }

        $out = $dir.'/assembled.zip';
        $dest = fopen($out, 'wb');
        if ($dest === false) {
            throw new StorageException('Failed to assemble chunks.');
        }

        foreach ($parts as $part) {
            $in = fopen($part, 'rb');
            if ($in === false) {
                fclose($dest);
                throw new StorageException("Failed to read chunk: {$part}");
            }
            stream_copy_to_stream($in, $dest);
            fclose($in);
        }
        fclose($dest);

        return $out;
    }

    public function cleanup(string $uploadId): void
    {
        $dir = $this->dir($uploadId);
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }

    private function dir(string $uploadId): string
    {
        if (! preg_match('/^[A-Za-z0-9-]{8,64}$/', $uploadId)) {
            throw new StorageException('Invalid upload id.');
        }

        return storage_path('app/artifacts/uploads/'.$uploadId);
    }
}
