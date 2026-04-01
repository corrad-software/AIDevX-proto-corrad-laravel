<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class ResetPasswordRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255', Rule::exists('users', 'email')],
            'token' => ['required', 'string', 'min:10'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
        ];
    }
}
