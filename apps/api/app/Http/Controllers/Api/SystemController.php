<?php

namespace App\Http\Controllers\Api;

use App\Domain\System\CapabilityProbe;
use App\Domain\System\CronHeartbeat;
use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;

/**
 * System/diagnostics endpoints (docs/SPEC.md §9.3). Admin session required.
 * Probe results are persisted to the `settings` store so the Admin UI can show the
 * last-known capabilities without re-probing on every request.
 */
class SystemController extends Controller
{
    private const CAPABILITIES_KEY = 'capabilities';

    /** Return the stored probe, running (and persisting) one on first access. */
    public function capabilities(CapabilityProbe $probe): JsonResponse
    {
        $payload = Setting::read(self::CAPABILITIES_KEY);

        // Re-probe when nothing is stored yet, or when a payload predates a probe-schema change
        // (e.g. `binaries` added) so the UI never receives a partial shape.
        if (! is_array($payload) || ! array_key_exists('binaries', $payload['result'] ?? [])) {
            $payload = $this->probeAndStore($probe);
        }

        return $this->respond($payload);
    }

    /** Force a fresh probe and persist it. */
    public function refreshCapabilities(CapabilityProbe $probe): JsonResponse
    {
        return $this->respond($this->probeAndStore($probe));
    }

    /** Cron liveness + cadence mode from the heartbeat store (docs/SPEC.md §10). */
    public function cron(CronHeartbeat $heartbeat): JsonResponse
    {
        return response()->json(['data' => $heartbeat->status()]);
    }

    /**
     * @return array{result: array<string, mixed>, probed_at: string}
     */
    private function probeAndStore(CapabilityProbe $probe): array
    {
        $payload = [
            'result' => $probe->run(),
            'probed_at' => now()->toIso8601String(),
        ];

        Setting::write(self::CAPABILITIES_KEY, $payload);

        return $payload;
    }

    /**
     * @param  array{result: array<string, mixed>, probed_at: string}  $payload
     */
    private function respond(array $payload): JsonResponse
    {
        return response()->json([
            'data' => $payload['result'],
            'probed_at' => $payload['probed_at'],
        ]);
    }
}
