<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Validator;

class OnboardingUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    public function authorize(): bool
    {
        return $this->user() instanceof User && $this->user()->requiresCredentialChange();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();
                $password = $this->input('password');

                if ($user instanceof User && is_string($password) && Hash::check($password, $user->password)) {
                    $validator->errors()->add('password', 'The new password must be different from the one-time password.');
                }
            },
        ];
    }
}
