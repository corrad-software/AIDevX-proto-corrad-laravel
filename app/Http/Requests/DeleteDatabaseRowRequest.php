<?php

namespace App\Http\Requests;

class DeleteDatabaseRowRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'primary_key' => ['required', 'array'],
        ];
    }
}
