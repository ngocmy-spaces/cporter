<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Append-only audit trail of sensitive actions (docs/SPEC.md §5, §12).
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null; // append-only: created_at only

    protected $fillable = [
        'actor',
        'action',
        'subject_type',
        'subject_id',
        'meta',
        'ip',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
