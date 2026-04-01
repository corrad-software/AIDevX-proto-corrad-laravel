<?php

namespace App\Http\Requests;

use App\Models\Role;

class UpdateRoleRequest extends BaseFormRequest
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
        $roleParam = $this->route('role');
        $roleId = $roleParam instanceof Role ? $roleParam->getKey() : (int) $roleParam;

        return [
            'name' => 'sometimes|required|string|min:1|unique:roles,name,'.$roleId,
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'string',
            'menu_access' => 'nullable|array',
            'menu_access.*' => 'string',
        ];
    }
}
