<?php

namespace App\Http\Requests;

class CreateDatabaseTableRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'table' => ['required', 'string', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*.name' => ['required', 'string', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'columns.*.type' => ['required', 'string', 'in:string,text,longText,integer,bigInteger,boolean,dateTime,json'],
            'columns.*.nullable' => ['nullable', 'boolean'],
            'primaryKey' => ['nullable', 'string', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'withTimestamps' => ['nullable', 'boolean'],
        ];
    }
}
