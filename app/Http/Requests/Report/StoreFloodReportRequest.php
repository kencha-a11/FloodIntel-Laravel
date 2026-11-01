<?php

namespace App\Http\Requests\Report;

use App\Enums\AlertLevel;
use App\Enums\FloodLevel;
use App\Enums\FloodReportStatus;
use App\Enums\FloodType;
use App\Models\FloodReport;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class StoreFloodReportRequest extends FormRequest
{
    /**
     * Mirrors the client's MAX_PHOTO_BYTES; the client compresses to a 1 MB
     * target, so only a payload queued by an older build reaches this ceiling.
     */
    private const MAX_PHOTO_BYTES = 5120 * 1024;

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
            'photo' => ['required', 'image', 'mimes:jpeg,png,webp'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reported_at' => ['required', 'date', 'before_or_equal:now'],
            // Opaque per-reporter idempotency key (D-110); the queue's own item
            // id, which is not always UUID-shaped.
            'client_uuid' => ['nullable', 'string', 'max:64'],
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

    /**
     * A size rejection is named on its own key so a client can tell it apart
     * from a content rejection and reduce the photo instead of giving up
     * (D-108); the message also stays under `photo` for field display.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $photo = $this->file('photo');

            if (
                $photo instanceof UploadedFile
                && $photo->isValid()
                && $photo->getSize() > self::MAX_PHOTO_BYTES
            ) {
                $message = 'The photo is larger than the 5 MB upload limit.';
                $validator->errors()->add('photo', $message);
                $validator->errors()->add('photo_size', $message);
            }
        });
    }
}
