<?php

namespace App\Http\Requests\Sitrep;

use Illuminate\Foundation\Http\FormRequest;

class IssueSituationalReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The issuer service enforces GenerateProvincialReport (D-42).
        return true;
    }

    public function rules(): array
    {
        return [
            'office' => ['nullable', 'array'],
            'office.country' => ['nullable', 'string', 'max:200'],
            'office.province' => ['nullable', 'string', 'max:200'],
            'office.municipality' => ['nullable', 'string', 'max:200'],
            'office.unit' => ['nullable', 'string', 'max:200'],
            'office.address' => ['nullable', 'string', 'max:300'],
            'office.email' => ['nullable', 'string', 'max:200'],
            'office.thru' => ['nullable', 'string', 'max:200'],
            'office.signatory' => ['nullable', 'string', 'max:200'],
            'office.signatory_title' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * The office metadata frozen onto the issuance: confirmed overrides on
     * top of the configured defaults (G4).
     *
     * @return array<string, mixed>
     */
    public function office(): array
    {
        $overrides = array_filter(
            $this->validated('office') ?? [],
            fn ($value): bool => $value !== null && $value !== '',
        );

        return [...config('sitrep.office'), ...$overrides];
    }
}
