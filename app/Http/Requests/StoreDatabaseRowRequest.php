<?php

namespace App\Http\Requests;

class StoreDatabaseRowRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'row' => ['required', 'array'],
        ];
    }
}
