<?php

namespace App\Http\Requests\Realtime;

use App\Realtime\ReportMessage;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordRealtimeLatencyRequest extends FormRequest
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
            'report_id' => ['required', 'integer', 'exists:flood_reports,id'],
            'event' => ['required', 'string', Rule::in([
                ReportMessage::CREATED,
                ReportMessage::STATUS_CHANGED,
            ])],
            'applied_at' => ['required', 'date'],
        ];
    }
}
