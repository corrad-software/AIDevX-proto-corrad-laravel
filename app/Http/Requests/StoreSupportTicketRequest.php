<?php

namespace App\Http\Requests;

class StoreSupportTicketRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject' => 'required|string|min:3|max:255',
            'description' => 'required|string|min:3',
            'module' => 'nullable|string|max:100',
            'type' => 'nullable|string|max:100',
            'priority' => 'nullable|in:low,normal,high,urgent',
        ];
    }
}
