<?php

namespace App\Models;

use App\Enums\FloodReportStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One row per status change, written only by FloodReportTransitions (D-22).
 *
 * `actor_name` and `actor_role` are denormalized so the entry survives the
 * hard deletion of the account that produced it.
 */
#[Fillable([
    'flood_report_id', 'user_id', 'actor_name', 'actor_role',
    'from_status', 'to_status', 'reason',
])]
class FloodReportStatusChange extends Model
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
            'from_status' => FloodReportStatus::class,
            'to_status' => FloodReportStatus::class,
        ];
    }

    /**
     * Get the report this entry belongs to.
     *
     * @return BelongsTo<FloodReport, $this>
     */
    public function floodReport(): BelongsTo
    {
        return $this->belongsTo(FloodReport::class);
    }

    /**
     * Get the account that made the change, if it still exists.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Boot the model: the log is append-only.
     */
    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Status change records are append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Status change records are append-only.');
        });
    }
}
