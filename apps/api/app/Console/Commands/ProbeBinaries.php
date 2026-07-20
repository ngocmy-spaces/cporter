<?php

namespace App\Console\Commands;

use App\Adapters\Command\CommandRunner;
use App\Domain\System\CapabilityProbe;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Detect deploy-hook CLIs (php/composer/node/npm/python3) from the cron shell (docs/SPEC.md §9).
 *
 * Web PHP has no proc_open, so the System view can only PATH-scan (a guess). This command runs in
 * cron's shell context — the same context hooks run in — and uses `command -v`, giving an
 * authoritative result. It persists to the `binaries` setting, which the /system/capabilities
 * endpoint overlays onto the probe. Self-throttled: the scheduler/worker may call it every pass,
 * but it only re-probes when the cached result is older than {@see self::TTL_HOURS}.
 */
class ProbeBinaries extends Command
{
    protected $signature = 'cporter:probe-binaries {--force : Re-probe even if the cached result is still fresh}';

    protected $description = 'Detect deploy-hook CLIs via the cron shell (command -v) and cache the result';

    private const TTL_HOURS = 6;

    public function handle(CommandRunner $runner, CapabilityProbe $probe): int
    {
        if (! $runner->isAvailable()) {
            // No shell here (e.g. accidentally invoked from web PHP) — leave the PATH-scan fallback.
            $this->info('cporter:probe-binaries — shell unavailable (proc_open); skipping.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && $this->isFresh()) {
            return self::SUCCESS;
        }

        Setting::write('binaries', [
            'result' => $probe->binariesViaShell($runner),
            'detected_at' => now()->toIso8601String(),
        ]);

        $this->info('cporter:probe-binaries — detected hook binaries via the shell.');

        return self::SUCCESS;
    }

    private function isFresh(): bool
    {
        $stored = Setting::read('binaries');
        if (! is_array($stored) || empty($stored['detected_at'])) {
            return false;
        }

        return Carbon::parse($stored['detected_at'])->greaterThan(now()->subHours(self::TTL_HOURS));
    }
}
