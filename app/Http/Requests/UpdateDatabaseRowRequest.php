<?php

namespace App\Http\Requests;

class UpdateDatabaseRowRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'primary_key' => ['required', 'array'],
            'row' => ['required', 'array'],
        ];
    }
}
