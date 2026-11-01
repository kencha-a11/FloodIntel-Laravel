<?php

namespace App\Http\Requests\Report;

use App\Enums\AlertLevel;
use App\Models\FloodReport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValidatedFeedRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('viewValidatedFeed', FloodReport::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'min_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:max_lat,min_lng,max_lng'],
            'max_lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:min_lat,min_lng,max_lng', 'gte:min_lat'],
            'min_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:min_lat,max_lat,max_lng'],
            'max_lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:min_lat,max_lat,min_lng', 'gte:min_lng'],
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
            'alert_level' => ['nullable', Rule::enum(AlertLevel::class)],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'limit' => ['nullable', 'integer', 'between:1,500'],
        ];
    }
}
