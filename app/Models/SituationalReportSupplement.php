<?php

namespace App\Models;

use App\Enums\SitrepSection;
use Database\Factories\SituationalReportSupplementFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A mutable command-center draft row for one SITREP section (D-147).
 * Preparing a snapshot freezes the current rows into the snapshot's content;
 * later edits affect only future revisions, never an issued document.
 */
#[Fillable([
    'event_id', 'section', 'barangay_id', 'payload', 'source', 'observed_at',
    'created_by_user_id', 'created_by_name',
])]
class SituationalReportSupplement extends Model
{
    /** @use HasFactory<SituationalReportSupplementFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'section' => SitrepSection::class,
            'payload' => 'array',
            'observed_at' => 'datetime',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(FloodEvent::class, 'event_id');
    }

    public function barangay(): BelongsTo
    {
        return $this->belongsTo(Barangay::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
