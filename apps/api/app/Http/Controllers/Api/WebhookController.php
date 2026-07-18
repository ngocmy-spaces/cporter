<?php

namespace App\Http\Controllers\Api;

use App\Domain\Audit\AuditLogger;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CI webhook receiver (docs/SPEC.md §7, §12). Verifies the provider signature (HMAC) and
 * records the event. cPorter deploys via the artifact push API (CI does the build+upload),
 * so this is a verified notification hook — a foundation for future auto-trigger flows.
 */
class WebhookController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $secret = (string) config('cporter.webhook_secret', '');
        if ($secret === '') {
            return response()->json(['error' => 'Webhooks are not configured.'], 503);
        }

        if (! $this->verify($provider, $request, $secret)) {
            return response()->json(['error' => 'Invalid webhook signature.'], 401);
        }

        $event = $request->header('X-GitHub-Event') ?? $request->header('X-Gitlab-Event') ?? 'unknown';
        $this->audit->record('webhook.received', null, ['provider' => $provider, 'event' => $event], "webhook:{$provider}");

        return response()->json(['data' => ['received' => true, 'provider' => $provider]], 202);
    }

    private function verify(string $provider, Request $request, string $secret): bool
    {
        return match ($provider) {
            'github' => $this->verifyGithub($request, $secret),
            'gitlab' => hash_equals($secret, (string) $request->header('X-Gitlab-Token')),
            default => false,
        };
    }

    private function verifyGithub(Request $request, string $secret): bool
    {
        $signature = (string) $request->header('X-Hub-Signature-256');
        if ($signature === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }
}
