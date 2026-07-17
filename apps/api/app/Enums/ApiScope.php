<?php

namespace App\Enums;

/**
 * Permission scopes granted to an API key (docs/SPEC.md §12).
 * `admin` implies all other scopes.
 */
enum ApiScope: string
{
    case Read = 'read';
    case Deploy = 'deploy';
    case Rollback = 'rollback';
    case Admin = 'admin';
}
