<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records sensitive actions to the append-only audit trail (docs/SPEC.md §12).
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(string $action, ?Model $subject = null, array $meta = [], ?string $actor = null): AuditLog
    {
        return AuditLog::create([
            'actor' => $actor ?? auth()->user()?->email ?? 'system',
            'action' => $action,
            'subject_type' => $subject !== null ? $subject::class : null,
            'subject_id' => $subject?->getKey(),
            'meta' => $meta !== [] ? $meta : null,
            'ip' => request()->ip(),
        ]);
    }
}
