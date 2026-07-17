<?php

namespace App\Enums;

/**
 * Upload/verification state of a CI artifact (docs/SPEC.md §5, §6).
 */
enum ArtifactStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
}
