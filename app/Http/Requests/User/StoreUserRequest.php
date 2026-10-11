<?php

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)],
            'contact_number' => ['required', 'string', 'max:255', Rule::unique(User::class)],
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required', Rule::enum(UserRole::class)],
            'barangay_id' => ['nullable', 'integer', Rule::exists(Barangay::class, 'id')],
        ];
    }

    /**
     * Get the additional validation callbacks.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $role = UserRole::tryFrom((string) $this->input('role'));

                if ($role?->requiresBarangay() && blank($this->input('barangay_id'))) {
                    $validator->errors()->add('barangay_id', 'A barangay is required for the selected role.');
                }
            },
        ];
    }
}
