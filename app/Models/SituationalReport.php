<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * An immutable situational-report snapshot (D-40, FR-81).
 *
 * The stored JSON is what a document renders from; no field is recomputed on
 * read, so the same revision always produces the same document. A correction
 * is a new row referencing its predecessor, never an update.
 */
#[Fillable([
    'window_starts_at', 'window_ends_at', 'cutoff_at', 'cutoff_label', 'timezone',
    'snapshot_at', 'revision', 'predecessor_id', 'format_version',
    'generated_by_user_id', 'generated_by_name', 'generated_by_role',
    'status_counts', 'totals', 'barangays', 'latency', 'stage_two',
])]
class SituationalReport extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'window_starts_at' => 'datetime',
            'window_ends_at' => 'datetime',
            'cutoff_at' => 'datetime',
            'snapshot_at' => 'datetime',
            'revision' => 'integer',
            'status_counts' => 'array',
            'totals' => 'array',
            'barangays' => 'array',
            'latency' => 'array',
            'stage_two' => 'array',
        ];
    }

    /**
     * Get the revision this one replaces.
     *
     * @return BelongsTo<SituationalReport, $this>
     */
    public function predecessor(): BelongsTo
    {
        return $this->belongsTo(self::class, 'predecessor_id');
    }

    /**
     * Get the account that generated the snapshot, if it still exists.
     *
     * @return BelongsTo<User, $this>
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by_user_id');
    }

    /**
     * Get the reports this revision published figures from.
     *
     * @return HasMany<SituationalReportInclusion, $this>
     */
    public function inclusions(): HasMany
    {
        return $this->hasMany(SituationalReportInclusion::class)->orderBy('id');
    }

    /**
     * Boot the model: an issued revision is never edited or deleted (FR-81).
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Situational reports are immutable; issue a new revision.');
        });

        static::deleting(function (): void {
            throw new LogicException('Situational reports are immutable; issue a new revision.');
        });
    }
}
