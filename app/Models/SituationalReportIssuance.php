<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An append-only issuance record (D-42, D-147): the officer who issued a
 * frozen snapshot, the per-event report number, and the office/signatory
 * metadata chosen at issue. The snapshot itself is never updated; a
 * correction is a new revision with its own issuance.
 */
#[Fillable([
    'situational_report_id', 'event_id', 'report_number',
    'issued_by_user_id', 'issued_by_name', 'issued_by_role', 'office', 'issued_at',
])]
class SituationalReportIssuance extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'report_number' => 'integer',
            'office' => 'array',
            'issued_at' => 'datetime',
        ];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(SituationalReport::class, 'situational_report_id');
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(FloodEvent::class, 'event_id');
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Issuance records are immutable; issue a new revision.');
        });

        static::deleting(function (): void {
            throw new LogicException('Issuance records are immutable; issue a new revision.');
        });
    }
}
