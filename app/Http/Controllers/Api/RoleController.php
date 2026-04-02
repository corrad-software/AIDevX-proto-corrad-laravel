<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoleRequest;
use App\Http\Requests\UpdateRoleRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Role;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;

class RoleController extends Controller
{
    use ApiResponse;

    /**
     * Persist description as empty string when omitted; DB column is nullable (MySQL disallows DEFAULT on TEXT).
     */
    private function normalizeDescription(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    /**
     * List all roles with user count.
     */
    public function index(): JsonResponse
    {
        $roles = Role::withCount('users')
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->sendOk($roles);
    }

    /**
     * Create a new role.
     */
    public function store(StoreRoleRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Check name uniqueness (case-insensitive)
        $existing = Role::whereRaw('LOWER(name) = ?', [strtolower($data['name'])])->first();
        if ($existing) {
            return $this->sendError(409, 'DUPLICATE_NAME', 'A role with this name already exists');
        }

        try {
            $role = Role::create([
                'name' => $data['name'],
                'description' => $this->normalizeDescription($data['description'] ?? null),
                'permissions' => $data['permissions'] ?? [],
                'menu_access' => $data['menu_access'] ?? null,
            ]);
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Integrity constraint')) {
                $msg = strtolower($e->getMessage());
                $looksLikeDuplicateName = str_contains($msg, 'roles.name') || str_contains($msg, 'unique');

                return $this->sendError(
                    409,
                    $looksLikeDuplicateName ? 'DUPLICATE_NAME' : 'CONSTRAINT_VIOLATION',
                    $looksLikeDuplicateName
                        ? 'A role with this name already exists'
                        : 'Could not save role. Check your input and try again.',
                    app()->environment('testing') ? $e->getMessage() : null,
                );
            }
            throw $e;
        }

        return $this->sendOk($role);
    }

    /**
     * Show a single role.
     */
    public function show(Role $role): JsonResponse
    {
        $role->loadCount('users');

        return $this->sendOk($role);
    }

    /**
     * Update an existing role.
     *
     * Only keys present in validated() are applied. Missing `permissions` / `menu_access`
     * no longer wipe existing values (fixes “success toast but DB unchanged” when the
     * client omits those keys from the validated payload).
     */
    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $data = $request->validated();

        if ($data === []) {
            return $this->sendError(
                400,
                'BAD_REQUEST',
                'No fields to update. Send JSON with one or more of: name, description, permissions, menu_access.',
            );
        }

        // Check name uniqueness if changed (case-insensitive)
        if (array_key_exists('name', $data) && strcasecmp($data['name'], $role->name) !== 0) {
            $nameTaken = Role::whereRaw('LOWER(name) = ?', [strtolower($data['name'])])
                ->where('id', '!=', $role->id)
                ->first();
            if ($nameTaken) {
                return $this->sendError(409, 'DUPLICATE_NAME', 'A role with this name already exists');
            }
        }

        $updates = [];
        if (array_key_exists('name', $data)) {
            $updates['name'] = $data['name'];
        }
        if (array_key_exists('description', $data)) {
            $updates['description'] = $this->normalizeDescription($data['description'] ?? null);
        }
        if (array_key_exists('permissions', $data)) {
            $updates['permissions'] = is_array($data['permissions']) ? $data['permissions'] : [];
        }
        if (array_key_exists('menu_access', $data)) {
            $updates['menu_access'] = $data['menu_access'];
        }

        try {
            if ($updates !== []) {
                $role->update($updates);
            }
            $role->refresh();
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'Integrity constraint')) {
                $msg = strtolower($e->getMessage());
                $looksLikeDuplicateName = str_contains($msg, 'roles.name') || str_contains($msg, 'unique');

                return $this->sendError(
                    409,
                    $looksLikeDuplicateName ? 'DUPLICATE_NAME' : 'CONSTRAINT_VIOLATION',
                    $looksLikeDuplicateName
                        ? 'A role with this name already exists'
                        : 'Could not save role. Check your input and try again.',
                    app()->environment('testing') ? $e->getMessage() : null,
                );
            }
            throw $e;
        }

        $role->loadCount('users');

        return $this->sendOk($role);
    }

    /**
     * Delete a role (only if not in use).
     */
    public function destroy(Role $role): JsonResponse
    {
        if ($role->users()->count() > 0) {
            return $this->sendError(409, 'ROLE_IN_USE', 'Role is currently assigned to users and cannot be deleted');
        }

        $role->delete();

        return $this->sendOk(['success' => true]);
    }
}
