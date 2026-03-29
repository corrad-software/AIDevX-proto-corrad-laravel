<?php

namespace App\Http\Requests;

class UpsertDatabaseRowRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'data' => ['required', 'array', 'min:1'],
        ];
    }
}
