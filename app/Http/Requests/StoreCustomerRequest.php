<?php

namespace App\Http\Requests;

class StoreCustomerRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'customer_code' => 'required|string|max:50|unique:customers,customer_code',
            'customer_name' => 'required|string|max:255',
            'contact_no' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'system_name' => 'nullable|string|max:50',
            'version' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ];
    }
}
