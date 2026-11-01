<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One propagation measurement reported by a subscriber (FR-70, D-67).
 *
 * A row with a null latency is an excluded sample: the server could not name a
 * receipt instant for the transition, or the client's applied_at was missing
 * or implausible. It is kept so the exclusion count is real.
 */
#[Fillable(['report_id', 'event', 'received_at', 'applied_at', 'latency_ms'])]
class RealtimeLatencySample extends Model
{
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'applied_at' => 'datetime',
            'latency_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FloodReport, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(FloodReport::class);
    }
}
