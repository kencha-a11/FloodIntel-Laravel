<?php

namespace App\Models;

use App\Enums\AlertLevel;
use App\Enums\FloodLevel;
use App\Enums\FloodReportStatus;
use App\Enums\FloodType;
use App\Enums\UserAbility;
use App\Policies\FloodReportPolicy;
use Database\Factories\FloodReportFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use LogicException;

#[Fillable([
    'user_id', 'barangay_id', 'flood_level', 'alert_level', 'flood_type',
    'depth_cm', 'landmark', 'latitude', 'longitude',
    'gps_accuracy', 'address', 'photo_path', 'notes', 'reported_at', 'status',
    'rejected_at', 'rejected_by', 'rejection_reason',
])]
#[Appends(['photo_url'])]
#[UsePolicy(FloodReportPolicy::class)]
class FloodReport extends Model
{
    /** @use HasFactory<FloodReportFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'flood_level' => FloodLevel::class,
            'alert_level' => AlertLevel::class,
            'flood_type' => FloodType::class,
            'status' => FloodReportStatus::class,
            'reported_at' => 'datetime',
            'rejected_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_accuracy' => 'decimal:2',
            'depth_cm' => 'integer',
        ];
    }

    /**
     * Get the user who submitted the report.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the barangay the report belongs to.
     *
     * @return BelongsTo<Barangay, $this>
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /**
     * Get the user who rejected the report.
     *
     * @return BelongsTo<User, $this>
     */
    public function rejectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /**
     * Boot the model: enforce immutability of the observation timestamp.
     */
    protected static function booted(): void
    {
        static::updating(function (FloodReport $report): void {
            if ($report->isDirty('reported_at')) {
                throw new LogicException('The observation time is immutable.');
            }
        });
    }

    /**
     * Get the public URL of the stored photo.
     *
     * @return Attribute<string, never>
     */
    protected function photoUrl(): Attribute
    {
        return Attribute::get(
            fn (): string => Storage::disk('public')->url($this->photo_path),
        );
    }

    /**
     * Scope the query to reports visible to the given user.
     *
     * @param  Builder<FloodReport>  $query
     * @return Builder<FloodReport>
     */
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
}
