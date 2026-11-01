<?php

namespace App\Http\Requests\Report;

use App\Models\FloodReport;

/**
 * The CSV export is an operator surface: it carries every barangay's rows,
 * so it is gated where the queue's cross-barangay view is gated (FR-29).
 */
class ExportFloodReportsRequest extends ReportLogFiltersRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('export', FloodReport::class) ?? false;
    }
}
