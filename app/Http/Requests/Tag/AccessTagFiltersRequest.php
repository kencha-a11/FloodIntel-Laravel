<?php

namespace App\Http\Requests\Tag;

use App\Enums\AccessTagType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The tag list's filter surface (FR-120).
 *
 * `barangay_id` is accepted here for the command center only; the controller
 * applies it only when the caller may read beyond its own barangay.
 */
class AccessTagFiltersRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'barangay_id' => ['nullable', 'integer', 'exists:barangays,id'],
            'type' => ['nullable', Rule::enum(AccessTagType::class)],
            'is_active' => ['nullable', 'boolean'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
