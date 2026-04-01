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
            'customer_name' => 'nullable|string|max:255',
            'system_name' => 'nullable|string|max:255',
            'module' => 'nullable|string|max:100',
            'type' => 'nullable|in:bugs,request,question',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'ai_assistance_enabled' => 'sometimes|boolean',
        ];
    }
}
