<?php

namespace App\Http\Requests;

class UpdateCustomerRequest extends BaseFormRequest
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
        $id = (int) $this->route('customer');

        return [
            'customer_code' => 'sometimes|string|max:50|unique:customers,customer_code,'.$id,
            'customer_name' => 'sometimes|string|max:255',
            'contact_no' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'system_name' => 'nullable|string|max:50',
            'version' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ];
    }
}
