<?php

namespace App\Http\Requests;

class UpdateSupportTicketRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'subject' => 'sometimes|required|string|min:3|max:255',
            'description' => 'sometimes|required|string|min:3',
            'customer_name' => 'nullable|string|max:255',
            'system_name' => 'nullable|string|max:255',
            'module' => 'nullable|string|max:100',
            'type' => 'nullable|in:bugs,request,question',
            'priority' => 'nullable|in:low,normal,high,urgent',
            'status' => 'nullable|in:new,assigned,in_progress,pending_requestor,resolved,closed',
            'ai_assistance_enabled' => 'sometimes|boolean',
        ];
    }
}
