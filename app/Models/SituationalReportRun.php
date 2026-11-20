<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An append-only scheduled/manual preparation attempt (D-44, D-147). The
 * current state of a cutoff is derived from its latest attempt; a cutoff
 * with no attempt after it passed is "not_run" — computed, never stored.
 */
#[Fillable([
    'cutoff_at', 'window_starts_at', 'state', 'reason',
    'situational_report_id', 'triggered_by', 'actor_user_id', 'actor_name',
])]
class SituationalReportRun extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'cutoff_at' => 'datetime',
            'window_starts_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(SituationalReport::class, 'situational_report_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Run outcomes are append-only; record a new attempt.');
        });

        static::deleting(function (): void {
            throw new LogicException('Run outcomes are append-only; record a new attempt.');
        });
    }
}
