<?php

namespace App\Enums;

/**
 * Kind of app a managed project runs (docs/SPEC.md §5, §16).
 */
enum ProjectType: string
{
    case Laravel = 'laravel';
    case StaticSite = 'static';
    case Php = 'php';
    case Node = 'node';
    case WordPress = 'wordpress';

    /**
     * Whether deploys of this type need shell commands (migrate/cache/restart)
     * routed through the cron-worker (docs/SPEC.md §9). Static/WP/PHP deploy
     * fully in web PHP with no shell.
     */
    public function requiresCommandRunner(): bool
    {
        return match ($this) {
            self::Laravel, self::Node => true,
            self::StaticSite, self::Php, self::WordPress => false,
        };
    }
}
