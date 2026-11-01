<?php

namespace App\Models;

use App\Enums\AccessTagType;
use App\Enums\UserAbility;
use App\Policies\AccessTagPolicy;
use Database\Factories\AccessTagFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'barangay_id', 'created_by', 'type', 'landmark', 'note',
    'latitude', 'longitude', 'gps_accuracy', 'address',
    'observed_at', 'is_active',
    'retired_at', 'retired_reason', 'retired_by',
])]
#[UsePolicy(AccessTagPolicy::class)]
class AccessTag extends Model
{
    /** @use HasFactory<AccessTagFactory> */
    use HasFactory;

    /**
     * The recorded columns that retirement never rewrites (FR-114, FR-121).
     *
     * @var array<int, string>
     */
    private const IMMUTABLE = [
        'barangay_id', 'created_by', 'type', 'landmark', 'note',
        'latitude', 'longitude', 'gps_accuracy', 'address', 'observed_at',
    ];

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
            'retired_at' => 'datetime',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'gps_accuracy' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Keep the recorded observation immutable and a retirement final (FR-121).
     */
    protected static function booted(): void
    {
        static::updating(function (AccessTag $tag): void {
            foreach (self::IMMUTABLE as $field) {
                if ($tag->isDirty($field)) {
                    throw new LogicException('The recorded observation is immutable.');
                }
            }

            if ($tag->getRawOriginal('retired_at') === null) {
                return;
            }

            foreach (['is_active', 'retired_at', 'retired_reason', 'retired_by'] as $field) {
                if ($tag->isDirty($field)) {
                    throw new LogicException('A tag is retired once.');
                }
            }
        });
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
     * Get the account that retired the tag, once it has one (FR-121).
     *
     * @return BelongsTo<User, $this>
     */
    public function retiredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'retired_by');
    }

    /**
     * Retire the tag without deleting it (FR-121, D-131).
     *
     * A second call is a no-op that keeps the first retirement: the instant,
     * the reason, and the actor are a single recorded event, so a retried
     * request cannot rewrite them.
     */
    public function retire(User $actor, ?string $reason = null): self
    {
        if (! $this->is_active) {
            return $this;
        }

        $this->forceFill([
            'is_active' => false,
            'retired_at' => now(),
            'retired_reason' => $reason,
            'retired_by' => $actor->id,
        ])->save();

        return $this;
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
