<?php

namespace App\Http\Requests\Report;

use App\Enums\AlertLevel;
use App\Enums\FloodLevel;
use App\Enums\FloodReportStatus;
use App\Enums\FloodType;
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
            'alert_level' => [
                'required_without:flood_level',
                function ($attribute, $value, $fail): void {
                    if ($value !== null && $this->input('flood_level') !== null) {
                        $fail('The alert level and flood level cannot both be provided.');
                    }
                },
                Rule::enum(AlertLevel::class),
            ],
            'flood_level' => [
                'required_without:alert_level',
                function ($attribute, $value, $fail): void {
                    if ($value !== null && $this->input('alert_level') !== null) {
                        $fail('The flood level and alert level cannot both be provided.');
                    }
                },
                Rule::enum(FloodLevel::class),
            ],
            'flood_type' => [
                'required_with:alert_level',
                Rule::enum(FloodType::class),
            ],
            'depth_cm' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'landmark' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy' => ['nullable', 'numeric', 'min:0'],
            'photo' => ['required', 'image', 'max:5120', 'mimes:jpeg,png,webp'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reported_at' => ['nullable', 'date', 'before_or_equal:now'],
            // A correction answers a return in the same barangay (FR-14); the
            // head check runs again under lock in the controller.
            'supersedes_id' => [
                'nullable',
                'integer',
                Rule::exists('flood_reports', 'id')->where(
                    fn ($query) => $query
                        ->where('barangay_id', $this->user()->barangay_id)
                        ->where('status', FloodReportStatus::Returned->value),
                ),
            ],
        ];
    }
}
