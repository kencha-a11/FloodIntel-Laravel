<?php

namespace App\Http\Requests\Tag;

use App\Enums\AccessTagType;
use App\Models\AccessTag;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAccessTagRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', AccessTag::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(AccessTagType::class)],
            // An unlisted constraint is never forced into a wrong case, so
            // `other` has to say what it means (D-127).
            'note' => [
                Rule::requiredIf(fn (): bool => $this->input('type') === AccessTagType::Other->value),
                'nullable',
                'string',
                'max:1000',
            ],
            'landmark' => ['nullable', 'string', 'max:255'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'gps_accuracy' => ['nullable', 'numeric', 'min:0'],
            'address' => ['nullable', 'string', 'max:255'],
            'observed_at' => ['required', 'date', 'before_or_equal:now'],
        ];
    }
}
