<?php

namespace App\Http\Requests;

class UploadKnowledgeDocRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => 'required|file|mimes:docx,doc,pdf,txt,md|max:51200',
            'module' => 'nullable|string|max:100',
            'name' => 'nullable|string|max:255',
        ];
    }
}
