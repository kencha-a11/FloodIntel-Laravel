<?php

namespace App\Http\Requests\Report;

use App\Enums\FloodLevel;
use App\Models\FloodReport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFloodReportRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', FloodReport::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'flood_level' => ['required', Rule::enum(FloodLevel::class)],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy' => ['nullable', 'numeric', 'min:0'],
            'photo' => ['required', 'image', 'max:5120', 'mimes:jpeg,png,webp'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reported_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }
}
