<?php

namespace App\Enums;

/**
 * The distinct post-activation failures the auto-rollback policy can react to (docs/SPEC.md §21.2).
 *
 * Each case is an independently opt-in trigger: a project's `auto_rollback_on` list decides which
 * of these swap `current` back to the previous release. Modelling triggers as an enum (rather than
 * a single boolean) keeps the deploy engine explicit about *why* it rolled back and makes the policy
 * extensible — a new trigger is one case here plus its detection call site; validation and the UI
 * pick it up from {@see self::options()} automatically.
 */
enum RollbackTrigger: string
{
    // The post-activation health check failed to return a 2xx within the timeout.
    case HealthCheckFailed = 'health_check';
    // A post-activate hook (e.g. `php artisan queue:restart`) exited non-zero on the live release.
    case PostActivateHookFailed = 'post_activate_hook';

    /** Human-readable label for audit notes and the admin UI. */
    public function label(): string
    {
        return match ($this) {
            self::HealthCheckFailed => 'Failed health check',
            self::PostActivateHookFailed => 'Failed post-activate hook',
        };
    }

    /**
     * All triggers as {value, label} pairs — the single source the API validation and the admin UI
     * build from, so adding a case above surfaces everywhere without further edits.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $t): array => ['value' => $t->value, 'label' => $t->label()],
            self::cases(),
        );
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $t): string => $t->value, self::cases());
    }
}
