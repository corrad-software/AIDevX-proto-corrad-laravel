<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Permission;
use App\Enums\UserLevel;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'photo_url',
        'role',
        'user_level',
        'user_jenis_pengguna',
        'customer_code',
        'managed_by_user_id',
        'is_active',
        'notes',
        'email_verified_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Get the roles that the user has.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'role_user')->withTimestamps();
    }

    /**
     * Get the customers that the user belongs to.
     */
    public function customers(): BelongsToMany
    {
        return $this->belongsToMany(Customer::class, 'customer_user')->withTimestamps();
    }

    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'managed_by_user_id');
    }

    public function managedUsers(): HasMany
    {
        return $this->hasMany(self::class, 'managed_by_user_id');
    }

    /**
     * Level 3 (agent) — match DB quirks: case, spaces, and aliases (agent / l3 / level3).
     */
    public function scopeWhereUserLevelIsAgent(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $column = $query->getConnection()->getQueryGrammar()->wrap($table.'.user_level');

        // whereIn(Expression, …) is driver-sensitive; whereRaw + grammar wrap works on MySQL & SQLite.
        return $query->whereRaw(
            'LOWER(TRIM(COALESCE('.$column.', \'\'))) IN (?, ?, ?)',
            ['agent', 'l3', 'level3']
        );
    }

    /**
     * Primary role (first) for backward compat.
     */
    public function roleModel(): ?Role
    {
        return $this->roles()->first();
    }

    /**
     * Primary customer (first) for backward compat.
     */
    public function customer(): ?Customer
    {
        return $this->customers()->first();
    }

    /**
     * Check if the user has a given permission (any of their roles).
     * Also grants permission if user has menu_access to an item that implies it.
     * Only Super Admin bypasses RBAC.
     */
    public function hasPermission(string $permission): bool
    {
        if ($this->user_level === UserLevel::SUPER_ADMIN) {
            return true;
        }
        foreach ($this->roles as $role) {
            if (is_array($role->permissions) && in_array($permission, $role->permissions)) {
                return true;
            }
        }
        if (in_array($permission, $this->getPermissionsImpliedByMenuAccess(), true)) {
            return true;
        }

        return false;
    }

    /**
     * Get all permissions the user has (from roles + implied by menu_access).
     * Only Super Admin returns all known permissions.
     */
    public function getAllPermissions(): array
    {
        if ($this->user_level === UserLevel::SUPER_ADMIN) {
            return Permission::all();
        }
        $merged = [];
        foreach ($this->roles as $role) {
            if (is_array($role->permissions)) {
                $merged = array_merge($merged, $role->permissions);
            }
        }
        $merged = array_merge($merged, $this->getPermissionsImpliedByMenuAccess());

        return array_values(array_unique($merged));
    }

    /**
     * Permissions implied by menu_access. If role has menu_access to an item,
     * user gets the permission needed to use it (e.g. kerisi-chat → chat.use).
     */
    protected function getPermissionsImpliedByMenuAccess(): array
    {
        $menuAccess = $this->getMenuAccess();
        if ($menuAccess === null || empty($menuAccess)) {
            return [];
        }
        $map = Permission::menuPermissionMap();
        $implied = [];
        foreach ($menuAccess as $menuId) {
            if (! isset($map[$menuId])) {
                continue;
            }
            $perms = $map[$menuId];
            $implied = array_merge($implied, is_array($perms) ? $perms : [$perms]);
        }

        return $implied;
    }

    /**
     * Get merged menu_access from all roles.
     */
    protected function getMenuAccess(): ?array
    {
        $roles = $this->roles;
        if ($roles->isEmpty()) {
            return $this->user_level === UserLevel::SUPER_ADMIN ? null : [];
        }
        $merged = [];
        foreach ($roles as $role) {
            $ma = $role->menu_access ?? [];
            if (! empty($ma)) {
                $merged = array_merge($merged, $ma);
            }
        }
        if (empty($merged)) {
            return $this->user_level === UserLevel::SUPER_ADMIN ? null : [];
        }

        return array_values(array_unique($merged));
    }

    public function canAccessSupportChat(): bool
    {
        return UserLevel::canAccessSupportChat($this->user_level ?? UserLevel::USER);
    }

    public function canAccessUserChat(): bool
    {
        return UserLevel::canAccessUserChat($this->user_level ?? UserLevel::USER);
    }
}
