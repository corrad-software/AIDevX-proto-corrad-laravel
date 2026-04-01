<?php

namespace App\Http\Requests;

class ReplySupportTicketRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'message' => 'required|string|min:1|max:5000',
            'is_internal' => 'nullable|boolean',
            'status' => 'nullable|in:in_progress,pending_requestor,resolved,closed',
            'mentioned_user_ids' => 'nullable|array|max:25',
            'mentioned_user_ids.*' => 'integer|exists:users,id',
        ];
    }
}
