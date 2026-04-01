<?php

namespace App\Http\Requests;

class AgentReplySuggestionRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'regenerate_prompt' => 'nullable|string|max:2000',
        ];
    }
}
