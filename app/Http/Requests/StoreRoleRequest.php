<?php

namespace App\Http\Requests;

class StoreRoleRequest extends BaseFormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('menuAccess') && ! $this->has('menu_access')) {
            $this->merge(['menu_access' => $this->input('menuAccess')]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => 'required|string|min:1|unique:roles,name',
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
            'menu_access' => 'nullable|array',
            'menu_access.*' => 'string',
        ];
    }
}
