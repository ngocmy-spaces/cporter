<?php

namespace App\Domain\Storage;

use RuntimeException;

/**
 * Raised when a filesystem/storage operation fails or is refused (docs/SPEC.md §6, §11).
 */
class StorageException extends RuntimeException
{
    public static function zipSlip(string $entry): self
    {
        return new self("Refusing to extract entry that escapes the release directory (Zip-Slip): {$entry}");
    }
}
