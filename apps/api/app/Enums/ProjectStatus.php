<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Active = 'active';
    case Disabled = 'disabled';
    // Transient: a disk purge is running; the project is soft-deleted (hidden) once it finishes.
    case Deleting = 'deleting';
}
