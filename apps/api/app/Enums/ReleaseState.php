<?php

namespace App\Enums;

/**
 * Lifecycle of a release directory (docs/SPEC.md §5, §6).
 */
enum ReleaseState: string
{
    case Pending = 'pending';
    case Extracting = 'extracting';
    case Ready = 'ready';
    case Active = 'active';
    case Superseded = 'superseded';
    case Failed = 'failed';
    // The release directory was reclaimed by retention (keep_releases). The DB row is kept for
    // history, but the release can no longer be activated — it is hidden from the Releases list.
    case Pruned = 'pruned';
}
