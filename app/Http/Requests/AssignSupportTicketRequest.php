<?php

namespace App\Http\Requests;

class AssignSupportTicketRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assigned_to_user_id' => 'required|integer|exists:users,id',
            'note' => 'nullable|string|max:500',
        ];
    }
}
