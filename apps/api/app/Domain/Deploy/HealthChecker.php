<?php

namespace App\Domain\Deploy;

use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Post-activation health check (docs/SPEC.md §6, §13). Polls the URL until it returns a
 * 2xx or the timeout elapses. Uses the HTTP client (cURL under the hood, and fakeable
 * in tests via Http::fake()).
 */
class HealthChecker
{
    public function check(string $url, int $timeoutSeconds, int $perRequestTimeout = 10): bool
    {
        $deadline = microtime(true) + max(0, $timeoutSeconds);

        while (true) {
            if ($this->attempt($url, $perRequestTimeout)) {
                return true;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(300_000); // 0.3s between retries
        }
    }

    private function attempt(string $url, int $perRequestTimeout): bool
    {
        try {
            return Http::timeout($perRequestTimeout)->get($url)->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
