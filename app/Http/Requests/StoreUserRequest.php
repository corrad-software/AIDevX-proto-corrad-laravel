<?php

namespace App\Http\Requests;

use App\Enums\UserLevel;
use Illuminate\Validation\Rule;

class StoreUserRequest extends BaseFormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];
        if ($this->has('name')) {
            $merge['name'] = trim((string) $this->input('name'));
        }
        if ($this->has('email')) {
            $merge['email'] = mb_strtolower(trim((string) $this->input('email')));
        }
        if ($this->has('notes')) {
            $n = $this->input('notes');
            if ($n === null || $n === '') {
                $merge['notes'] = null;
            } elseif (is_string($n)) {
                $merge['notes'] = trim($n) === '' ? null : $n;
            }
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:255'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'max:255'],
            'role_ids' => ['nullable', 'array', 'max:100'],
            'role_ids.*' => ['integer', 'distinct', 'exists:roles,id'],
            'user_level' => ['nullable', 'string', Rule::in(UserLevel::all())],
            'user_jenis_pengguna' => ['nullable', 'string', 'max:50'],
            'customer_ids' => ['nullable', 'array', 'max:100'],
            'customer_ids.*' => ['integer', 'distinct', 'exists:customers,id'],
            'is_active' => ['nullable', 'boolean'],
            'managed_agent_ids' => ['nullable', 'array', 'max:500'],
            'managed_agent_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'customer_agent_assignments' => ['nullable', 'array', 'max:500'],
            'customer_agent_assignments.*.customer_id' => ['required', 'integer', 'exists:customers,id'],
            'customer_agent_assignments.*.agent_ids' => ['nullable', 'array', 'max:200'],
            'customer_agent_assignments.*.agent_ids.*' => ['integer', 'distinct', 'exists:users,id'],
            'notes' => ['nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama penuh',
            'email' => 'e-mel',
            'password' => 'kata laluan',
            'role_ids' => 'peranan',
            'role_ids.*' => 'peranan',
            'user_level' => 'tahap pengguna',
            'user_jenis_pengguna' => 'jenis pengguna',
            'customer_ids' => 'pelanggan',
            'customer_ids.*' => 'pelanggan',
            'is_active' => 'aktif',
            'managed_agent_ids' => 'ejen dilantik',
            'managed_agent_ids.*' => 'ejen',
            'customer_agent_assignments' => 'ejen mengikut pelanggan',
            'customer_agent_assignments.*.customer_id' => 'pelanggan',
            'customer_agent_assignments.*.agent_ids' => 'ejen',
            'customer_agent_assignments.*.agent_ids.*' => 'ejen',
            'notes' => 'catatan',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Nama penuh diperlukan.',
            'name.max' => 'Nama tidak boleh melebihi :max aksara.',
            'email.required' => 'E-mel diperlukan.',
            'email.email' => 'Format e-mel tidak sah.',
            'email.unique' => 'E-mel ini sudah digunakan.',
            'password.required' => 'Kata laluan diperlukan.',
            'password.min' => 'Kata laluan sekurang-kurangnya :min aksara.',
            'user_level.in' => 'Tahap pengguna tidak sah.',
            'role_ids.*.exists' => 'Peranan yang dipilih tidak wujud.',
            'customer_ids.*.exists' => 'Pelanggan yang dipilih tidak wujud.',
            'managed_agent_ids.*.exists' => 'Pengguna ejen yang dipilih tidak wujud.',
            'notes.max' => 'Catatan tidak boleh melebihi :max aksara.',
        ];
    }
}
