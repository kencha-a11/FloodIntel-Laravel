<?php

namespace App\Models;

use App\Enums\FloodImpactStatus;
use App\Enums\UserAbility;
use App\Policies\FloodImpactReportPolicy;
use Database\Factories\FloodImpactReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'user_id', 'barangay_id', 'event_id', 'families', 'individuals',
    'breakdown', 'related_level_report_ids', 'remarks', 'reported_at',
    'status', 'supersedes_id', 'client_uuid',
])]
#[UsePolicy(FloodImpactReportPolicy::class)]
class FloodImpactReport extends Model
{
    /** @use HasFactory<FloodImpactReportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => FloodImpactStatus::class,
            'reported_at' => 'datetime',
            'breakdown' => 'array',
            'related_level_report_ids' => 'array',
            'families' => 'integer',
            'individuals' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(FloodEvent::class, 'event_id');
    }

    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_id');
    }

    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasAbility(UserAbility::ViewAllBarangays)) {
            return $query;
        }
        if ($user->hasAbility(UserAbility::ViewOwnBarangay)) {
            return $query->where('barangay_id', $user->barangay_id);
        }

        return $query->where('user_id', $user->id);
    }

    /**
     * The observation time is written once, by the filing request (FR-13).
     */
    protected static function booted(): void
    {
        static::updating(function (FloodImpactReport $report): void {
            if ($report->isDirty('reported_at')) {
                throw new LogicException('The observation time is immutable.');
            }
        });
    }
}
