<?php

namespace App\Models;

use App\Enums\AlertLevel;
use App\Enums\FloodLevel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One included report's provenance for an issued revision (D-40).
 *
 * The observation and validation instants are copied rather than re-read so a
 * published figure stays traceable even after the report's status changes.
 */
#[Fillable([
    'situational_report_id', 'flood_report_id', 'barangay_id',
    'alert_level', 'flood_level', 'observed_at', 'validated_at',
])]
class SituationalReportInclusion extends Model
{
    public const UPDATED_AT = null;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'alert_level' => AlertLevel::class,
            'flood_level' => FloodLevel::class,
            'observed_at' => 'datetime',
            'validated_at' => 'datetime',
        ];
    }

    /**
     * Get the revision this row belongs to.
     *
     * @return BelongsTo<SituationalReport, $this>
     */
    public function situationalReport(): BelongsTo
    {
        return $this->belongsTo(SituationalReport::class);
    }

    /**
     * Get the report this row records.
     *
     * @return BelongsTo<FloodReport, $this>
     */
    public function floodReport(): BelongsTo
    {
        return $this->belongsTo(FloodReport::class);
    }

    /**
     * Get the barangay the report was filed in.
     *
     * @return BelongsTo<Barangay, $this>
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /**
     * Boot the model: provenance is written with its revision and never edited.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Situational report inclusions are immutable.');
        });

        static::deleting(function (): void {
            throw new LogicException('Situational report inclusions are immutable.');
        });
    }
}
