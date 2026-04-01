<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

class RegisterRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|min:1|max:255',
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')],
            'password' => 'required|string|min:6|confirmed',
            'customer_code' => [
                'required',
                'string',
                Rule::exists('customers', 'customer_code')->where('is_active', true),
            ],
        ];
    }
}
