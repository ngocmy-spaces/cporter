<?php

namespace App\Http\Controllers\Api;

use App\Domain\System\CapabilityProbe;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * System/diagnostics endpoints (docs/SPEC.md §9.3). Admin session required.
 */
class SystemController extends Controller
{
    public function capabilities(CapabilityProbe $probe): JsonResponse
    {
        return response()->json(['data' => $probe->run()]);
    }
}
