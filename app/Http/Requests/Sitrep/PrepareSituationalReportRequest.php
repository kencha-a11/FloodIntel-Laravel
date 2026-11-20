<?php

namespace App\Http\Requests\Sitrep;

use Illuminate\Foundation\Http\FormRequest;

class PrepareSituationalReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The aggregator enforces the ability (D-42, D-147); the route group
        // already limits this surface to the command center.
        return true;
    }

    public function rules(): array
    {
        return [
            'event_id' => ['nullable', 'integer', 'exists:flood_events,id'],
        ];
    }
}
