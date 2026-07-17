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
}
