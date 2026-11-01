<?php

namespace App\Models;

use App\Enums\AccessTagType;
use App\Enums\UserAbility;
use Database\Factories\AccessTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'barangay_id', 'created_by', 'type', 'landmark', 'note',
    'latitude', 'longitude', 'gps_accuracy', 'address',
    'observed_at', 'is_active',
])]
class AccessTag extends Model
{
    /** @use HasFactory<AccessTagFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AccessTagType::class,
            'observed_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_accuracy' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the barangay whose routes the tag describes.
     *
     * @return BelongsTo<Barangay, $this>
     */
    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    /**
     * Get the account that recorded the tag.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope the query to tags visible to the given user (FR-119).
     *
     * @param  Builder<AccessTag>  $query
     * @return Builder<AccessTag>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasAbility(UserAbility::ViewAllBarangays)) {
            return $query;
        }

        if ($user->hasAbility(UserAbility::ViewOwnBarangay)) {
            return $query->where('barangay_id', $user->barangay_id);
        }

        return $query->where('created_by', $user->id);
    }
}
