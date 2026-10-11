<?php

namespace App\Http\Requests\User;

use App\Enums\UserRole;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->route('user');

        if (! $this->user()->can('update', $user)) {
            return false;
        }

        $changingOwnRole = $this->user()->is($user)
            && $this->filled('role')
            && $this->string('role')->toString() !== $user->role->value;

        return ! $changingOwnRole;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'string', 'lowercase', 'email', 'max:255', Rule::unique(User::class)->ignore($user)],
            'contact_number' => ['sometimes', 'string', 'max:255', Rule::unique(User::class)->ignore($user)],
            'role' => ['sometimes', Rule::enum(UserRole::class)],
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
                $user = $this->route('user');
                $role = UserRole::tryFrom((string) $this->input('role')) ?? $user->role;
                $barangayId = $this->has('barangay_id') ? $this->input('barangay_id') : $user->barangay_id;

                if ($role->requiresBarangay() && blank($barangayId)) {
                    $validator->errors()->add('barangay_id', 'A barangay is required for the selected role.');
                }
            },
        ];
    }
}
