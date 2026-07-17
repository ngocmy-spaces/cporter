<?php

namespace App\Domain\Storage;

use RuntimeException;

/**
 * Thrown when a path resolves outside the configured allowed base paths (docs/SPEC.md §11, §12).
 */
class PathOutsideJailException extends RuntimeException
{
    public static function for(string $path): self
    {
        return new self("Path is outside the allowed base paths (jail): {$path}");
    }
}
