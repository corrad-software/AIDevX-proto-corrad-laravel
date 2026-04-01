<?php

namespace App\Http\Requests;

class AdminSendNotificationRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'notification_type' => ['nullable', 'string', 'in:system,user'],
            'module' => ['nullable', 'string', 'max:64'],
            'send_email' => ['nullable', 'boolean'],
        ];
    }
}
