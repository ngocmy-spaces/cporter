<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin (session) view of the audit trail (docs/SPEC.md §12).
 */
class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $logs]);
    }

    /**
     * Project-scoped activity feed: the audit-trail entries whose subject is this project
     * (create / update / preflight / delete …). Optional `?action=` filter, newest first.
     */
    public function project(Request $request, Project $project): JsonResponse
    {
        $logs = AuditLog::query()
            ->where('subject_type', Project::class)
            ->where('subject_id', $project->id)
            ->when($request->filled('action'), fn ($q) => $q->where('action', $request->string('action')))
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $logs]);
    }
}
