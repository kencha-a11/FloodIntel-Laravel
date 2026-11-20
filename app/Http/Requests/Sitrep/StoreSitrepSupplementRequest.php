<?php

namespace App\Http\Requests\Sitrep;

use App\Enums\SitrepSection;
use App\Support\SitrepPayloadGuard;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSitrepSupplementRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Drafts are command-center work product; the route group and RLS
        // both limit writes to mdrrmo_officer / mdrrmo_staff (D-42).
        return true;
    }

    public function rules(): array
    {
        $section = SitrepSection::tryFrom((string) $this->input('section'));

        return [
            'event_id' => ['required', 'integer', 'exists:flood_events,id'],
            'section' => ['required', Rule::enum(SitrepSection::class)],
            'barangay_id' => [
                Rule::requiredIf($section?->isBarangayScoped() === true),
                'nullable',
                'integer',
                'exists:barangays,id',
            ],
            'payload' => ['required', 'array'],
            'source' => ['nullable', 'string', 'max:500'],
            'observed_at' => ['nullable', 'date', 'before_or_equal:now'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function () use ($validator): void {
            if ($validator->errors()->isEmpty() && is_array($this->input('payload'))) {
                SitrepPayloadGuard::assertClean($this->input('payload'));
            }
        });
    }
}
