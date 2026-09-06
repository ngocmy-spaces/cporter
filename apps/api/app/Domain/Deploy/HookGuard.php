<?php

namespace App\Domain\Deploy;

/**
 * Refuses hook commands that drop or rewrite the target app's schema (docs/SPEC.md §23).
 *
 * A deploy hook runs unattended on every release, so `migrate:fresh` in a hook is a loaded gun:
 * one deploy wipes the database of the very app it is shipping. Enforced twice — when the project
 * is saved (ProjectController validation) and again immediately before execution (DeployEngine),
 * so hooks stored before this guard existed are still caught.
 *
 * Config-driven (`cporter.hooks.blocked_commands`); an empty list disables the guard.
 */
class HookGuard
{
    /** The blocked token found in $command, or null when the command is allowed. */
    public static function blocked(string $command): ?string
    {
        foreach ((array) config('cporter.hooks.blocked_commands', []) as $needle) {
            $needle = trim((string) $needle);
            if ($needle === '') {
                continue;
            }

            // Whole-token match: `migrate:fresh` must not match `migrate:freshen`, and plain
            // `migrate` must not match `migrate:fresh`.
            $pattern = '/(?<![\w:.\-])'.preg_quote($needle, '/').'(?![\w:.\-])/';
            if (preg_match($pattern, $command) === 1) {
                return $needle;
            }
        }

        return null;
    }

    /** Operator-facing explanation for a blocked command. */
    public static function reason(string $blocked): string
    {
        return "Hook command '{$blocked}' is blocked: it drops or rewrites the target app's schema, and a "
            .'deploy hook runs unattended on every release. Run it by hand over Terminal/SSH if you really '
            .'mean to (or change cporter.hooks.blocked_commands / CPORTER_BLOCKED_HOOK_COMMANDS).';
    }
}
