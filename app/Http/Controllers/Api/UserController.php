<?php

namespace App\Http\Controllers\Api;

use App\Enums\UserLevel;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreUserRequest;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Traits\ApiResponse;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\AppNotificationService;
use App\Services\UserHierarchyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class UserController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AppNotificationService $appNotifications,
        protected UserHierarchyService $hierarchy,
    ) {}

    private function formatUser(User $user): array
    {
        $user->loadMissing(['roles', 'customers']);

        $roles = $user->roles->map(fn ($r) => ['id' => $r->id, 'name' => $r->name])->values()->all();
        $customers = $user->customers->map(fn ($c) => ['id' => $c->id, 'customer_code' => $c->customer_code, 'customer_name' => $c->customer_name])->values()->all();

        $managedAgentIds = User::query()
            ->where('managed_by_user_id', $user->id)
            ->whereUserLevelIsAgent()
            ->orderBy('name')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        $customerAgentAssignments = [];
        if (Schema::hasTable('manager_customer_agents')) {
            $mcaRows = DB::table('manager_customer_agents')
                ->where('manager_user_id', $user->id)
                ->orderBy('customer_id')
                ->orderBy('agent_user_id')
                ->get(['customer_id', 'agent_user_id']);
            $byCustomer = [];
            foreach ($mcaRows as $r) {
                $cid = (int) $r->customer_id;
                if (! isset($byCustomer[$cid])) {
                    $byCustomer[$cid] = [];
                }
                $byCustomer[$cid][] = (int) $r->agent_user_id;
            }
            foreach ($byCustomer as $cid => $aids) {
                $customerAgentAssignments[] = [
                    'customer_id' => $cid,
                    'agent_ids' => array_values(array_unique($aids)),
                ];
            }
        }

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'user_level' => $user->user_level ?? 'user',
            'user_jenis_pengguna' => $user->user_jenis_pengguna,
            'customer_code' => $user->customer_code,
            'managed_by_user_id' => $user->managed_by_user_id,
            'managed_agent_ids' => $managedAgentIds,
            'is_active' => $user->is_active,
            'notes' => $user->notes,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'roles' => $roles,
            'role_ids' => $user->roles->pluck('id')->values()->all(),
            'customers' => $customers,
            'customer_ids' => $user->customers->pluck('id')->values()->all(),
            'customer_agent_assignments' => $customerAgentAssignments,
        ];
    }

    /**
     * @param  list<int>  $agentIds
     */
    private function syncManagedAgentsForManager(User $manager, array $agentIds, User $actor): ?JsonResponse
    {
        if (! UserLevel::canHaveManagedAgents($manager->user_level ?? UserLevel::USER)) {
            return $this->sendError(422, 'VALIDATION_ERROR', 'This user level cannot have managed agents');
        }

        $wanted = array_values(array_unique(array_map('intval', $agentIds)));

        foreach ($wanted as $agentId) {
            $agent = User::find($agentId);
            if (! $agent || UserLevel::normalize($agent->user_level ?? UserLevel::USER) !== UserLevel::AGENT) {
                return $this->sendError(422, 'VALIDATION_ERROR', 'Each managed user must be an agent');
            }
            if (! $this->hierarchy->canSeeUser($actor, $agent)) {
                return $this->sendError(403, 'FORBIDDEN', 'You cannot assign one or more agents');
            }
        }

        if (! $this->hierarchy->canSeeUser($actor, $manager)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot edit this manager');
        }

        $current = User::query()
            ->where('managed_by_user_id', $manager->id)
            ->whereUserLevelIsAgent()
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($wanted as $agentId) {
            User::query()->where('id', $agentId)->update(['managed_by_user_id' => $manager->id]);
        }

        $toClear = array_diff($current, $wanted);
        foreach ($toClear as $uid) {
            User::query()->where('id', $uid)->where('managed_by_user_id', $manager->id)->update(['managed_by_user_id' => null]);
        }

        $this->syncUserManagedAgentsPivot($manager, $wanted);

        return null;
    }

    /**
     * Mirror of users.managed_by_user_id for agents — explicit rows like customer_user.
     *
     * @param  list<int>  $wantedAgentIds
     */
    private function syncUserManagedAgentsPivot(User $manager, array $wantedAgentIds): void
    {
        if (! Schema::hasTable('user_managed_agents')) {
            return;
        }

        DB::table('user_managed_agents')->where('manager_user_id', $manager->id)->delete();

        foreach ($wantedAgentIds as $agentId) {
            DB::table('user_managed_agents')->where('agent_user_id', $agentId)->delete();
        }

        foreach ($wantedAgentIds as $agentId) {
            DB::table('user_managed_agents')->insert([
                'manager_user_id' => $manager->id,
                'agent_user_id' => $agentId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function clearManagedAgentsForManager(User $manager): void
    {
        if (Schema::hasTable('user_managed_agents')) {
            DB::table('user_managed_agents')->where('manager_user_id', $manager->id)->delete();
        }

        if (Schema::hasTable('manager_customer_agents')) {
            DB::table('manager_customer_agents')->where('manager_user_id', $manager->id)->delete();
        }

        User::query()
            ->where('managed_by_user_id', $manager->id)
            ->whereUserLevelIsAgent()
            ->update(['managed_by_user_id' => null]);
    }

    /**
     * @param  list<array{customer_id?: int, agent_ids?: list<int>}>  $rows
     */
    private function syncCustomerAgentAssignments(User $manager, array $rows, User $actor): ?JsonResponse
    {
        if (! Schema::hasTable('manager_customer_agents')) {
            return null;
        }

        if (! UserLevel::canHaveManagedAgents($manager->user_level ?? UserLevel::USER)) {
            if ($rows !== []) {
                return $this->sendError(422, 'VALIDATION_ERROR', 'Customer-specific agents only apply to Level 0–3 users');
            }

            return null;
        }

        if (! $this->hierarchy->canSeeUser($actor, $manager)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot edit this manager');
        }

        $manager->loadMissing('customers');
        $managerCustomerIds = $manager->customers->pluck('id')->map(fn ($id) => (int) $id)->all();

        $byCustomer = [];
        foreach ($rows as $row) {
            $cid = (int) ($row['customer_id'] ?? 0);
            if ($cid === 0) {
                continue;
            }
            foreach ($row['agent_ids'] ?? [] as $aid) {
                $byCustomer[$cid][(int) $aid] = true;
            }
        }

        DB::table('manager_customer_agents')->where('manager_user_id', $manager->id)->delete();

        foreach ($byCustomer as $cid => $agentIdSet) {
            $agentIds = array_keys($agentIdSet);
            if ($agentIds === []) {
                continue;
            }

            if (! in_array((int) $cid, $managerCustomerIds, true)) {
                return $this->sendError(422, 'VALIDATION_ERROR', 'Each customer in agent assignments must be linked to this user (Customers)');
            }

            foreach ($agentIds as $agentId) {
                $agent = User::query()->with('customers')->find($agentId);
                if (! $agent || UserLevel::normalize($agent->user_level ?? UserLevel::USER) !== UserLevel::AGENT) {
                    return $this->sendError(422, 'VALIDATION_ERROR', 'Each assigned user must be an agent');
                }
                if (! $this->hierarchy->canSeeUser($actor, $agent)) {
                    return $this->sendError(403, 'FORBIDDEN', 'You cannot assign one or more agents');
                }
                if ((int) $agent->managed_by_user_id !== (int) $manager->id) {
                    return $this->sendError(422, 'VALIDATION_ERROR', 'Agents must be in this manager’s Agent list (reporting line) before per-customer assignment');
                }
                $hasCustomer = $agent->customers->contains('id', (int) $cid);
                if (! $hasCustomer) {
                    return $this->sendError(422, 'VALIDATION_ERROR', 'Each agent must be linked to the same customer (assign customer on the agent user first)');
                }

                DB::table('manager_customer_agents')->insert([
                    'manager_user_id' => $manager->id,
                    'customer_id' => (int) $cid,
                    'agent_user_id' => $agentId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        return null;
    }

    public function index(Request $request): JsonResponse
    {
        $page = (int) $request->input('page', 1);
        $limit = (int) $request->input('limit', 10);
        $q = $request->input('q');
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');

        $query = User::query();
        $actor = $request->user();
        $visibleIds = $this->hierarchy->visibleUserIdsFor($actor, true);
        if (UserLevel::normalize($actor->user_level ?? UserLevel::USER) !== UserLevel::SUPER_ADMIN) {
            if ($visibleIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $visibleIds);
            }
        }

        if ($q) {
            $query->where(function ($builder) use ($q) {
                $builder->where('name', 'like', "%{$q}%")
                    ->orWhere('email', 'like', "%{$q}%");
            });
        }

        if ($request->filled('user_level')) {
            $query->where('user_level', UserLevel::normalize((string) $request->input('user_level')));
        }

        $total = $query->count();

        $rows = $query->orderBy($sortBy, $sortDir)
            ->skip(($page - 1) * $limit)
            ->take($limit)
            ->get()
            ->map(fn ($user) => $this->formatUser($user));

        return $this->sendOk($rows, [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'totalPages' => (int) ceil($total / $limit),
        ]);
    }

    /**
     * Agents the current user may assign when editing managers (create/update user UI).
     * Same visibility rules as GET /users?user_level=agent but dedicated route (no pagination confusion).
     */
    public function agentPicklist(Request $request): JsonResponse
    {
        $actor = $request->user();
        $excludeRaw = $request->input('exclude_user_id');
        $excludeId = ($excludeRaw !== null && $excludeRaw !== '') ? (int) $excludeRaw : null;

        $query = User::query()->whereUserLevelIsAgent();

        $forMention = filter_var($request->query('for_mention'), FILTER_VALIDATE_BOOLEAN);
        $assignableIds = $forMention
            ? $this->hierarchy->mentionableAgentIdsForTicket($actor)
            : $this->hierarchy->assignableAgentIdsForTicket($actor);
        if ($assignableIds === []) {
            $query->whereRaw('1 = 0');
        } else {
            $query->whereIn('id', $assignableIds);
        }

        if ($excludeId) {
            $query->where('id', '!=', $excludeId);
        }

        $customerIdRaw = $request->query('customer_id');
        if ($customerIdRaw !== null && $customerIdRaw !== '') {
            $cid = (int) $customerIdRaw;
            if ($cid > 0) {
                $query->whereHas('customers', function ($q) use ($cid) {
                    $q->where('customers.id', $cid);
                });
            }
        }

        $rows = $query->orderBy('name')->limit(500)->get();

        $data = $rows->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'user_level' => $u->user_level ?? UserLevel::USER,
        ])->values()->all();

        return $this->sendOk($data);
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $data = $request->validated();

        $actor = $request->user();
        $targetLevel = UserLevel::normalize($data['user_level'] ?? UserLevel::USER);
        if (! UserLevel::actorCanAssignLevel($actor->user_level ?? UserLevel::USER, $targetLevel)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot assign this user level');
        }

        if (array_key_exists('managed_agent_ids', $data) && ! empty($data['managed_agent_ids']) && ! UserLevel::canHaveManagedAgents($targetLevel)) {
            return $this->sendError(422, 'VALIDATION_ERROR', 'Managed agents only apply to Level 0–3 users (not end users / Level 4)');
        }

        $roleIds = $data['role_ids'] ?? [];
        $customerIds = $data['customer_ids'] ?? [];

        $firstRole = ! empty($roleIds) ? Role::find($roleIds[0]) : null;
        $firstCustomer = ! empty($customerIds) ? Customer::find($customerIds[0]) : null;

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => $firstRole?->name ?? 'user',
            'user_level' => $targetLevel,
            'user_jenis_pengguna' => array_key_exists('user_jenis_pengguna', $data) ? ($data['user_jenis_pengguna'] !== '' ? (string) $data['user_jenis_pengguna'] : null) : null,
            'customer_code' => $firstCustomer?->customer_code,
            'managed_by_user_id' => $actor?->id,
            'is_active' => $data['is_active'] ?? true,
            'notes' => array_key_exists('notes', $data) ? ($data['notes'] !== null && $data['notes'] !== '' ? (string) $data['notes'] : null) : null,
            'email_verified_at' => now(),
        ]);

        if (! empty($roleIds)) {
            $user->roles()->sync($roleIds);
        }
        if (! empty($customerIds)) {
            $user->customers()->sync($customerIds);
        }

        $user->refresh();

        if (array_key_exists('managed_agent_ids', $data) && UserLevel::canHaveManagedAgents($targetLevel)) {
            $syncErr = $this->syncManagedAgentsForManager($user, $data['managed_agent_ids'] ?? [], $actor);
            if ($syncErr !== null) {
                return $syncErr;
            }
        }

        if (array_key_exists('customer_agent_assignments', $data) && UserLevel::canHaveManagedAgents($targetLevel)) {
            $syncErr = $this->syncCustomerAgentAssignments(
                $user->fresh(),
                $data['customer_agent_assignments'] ?? [],
                $actor
            );
            if ($syncErr !== null) {
                return $syncErr;
            }
        }

        $fresh = $user->fresh();
        if ($fresh) {
            $this->appNotifications->notifyUserCreated($fresh, Auth::user());
        }

        return $this->sendCreated($this->formatUser($fresh));
    }

    public function show(int|string $id): JsonResponse
    {
        $user = User::find((int) $id);

        if (! $user) {
            return $this->sendError(404, 'NOT_FOUND', 'User not found');
        }

        $actor = Auth::user();
        if ($actor && ! $this->hierarchy->canSeeUser($actor, $user)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot view this user');
        }

        return $this->sendOk($this->formatUser($user));
    }

    public function update(UpdateUserRequest $request, int|string $id): JsonResponse
    {
        $user = User::find((int) $id);

        if (! $user) {
            return $this->sendError(404, 'NOT_FOUND', 'User not found');
        }

        $actor = $request->user();
        if (! $this->hierarchy->canSeeUser($actor, $user)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot edit this user');
        }
        if (! UserLevel::actorCanEditTargetUser($actor->user_level ?? UserLevel::USER, $user->user_level ?? UserLevel::USER)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot edit this user');
        }

        $data = $request->validated();

        if (isset($data['user_level'])) {
            $newLevel = UserLevel::normalize($data['user_level']);
            $currentLevel = UserLevel::normalize($user->user_level ?? UserLevel::USER);
            $isSelf = (int) $actor->id === (int) $user->id;
            // Edit sendiri: kekalkan tahap sendiri (payload sama) tidak dikira "assign level" — elak 403 bila simpan ejen dilantik.
            $keepingOwnLevel = $isSelf && $newLevel === $currentLevel;
            if (! $keepingOwnLevel && ! UserLevel::actorCanAssignLevel($actor->user_level ?? UserLevel::USER, $newLevel)) {
                return $this->sendError(403, 'FORBIDDEN', 'You cannot assign this user level');
            }
            $data['user_level'] = $newLevel;
        }

        $updateData = [];
        if (isset($data['name'])) {
            $updateData['name'] = $data['name'];
        }
        if (isset($data['email'])) {
            $updateData['email'] = $data['email'];
        }
        if (isset($data['user_level'])) {
            $updateData['user_level'] = $data['user_level'];
        }
        if (array_key_exists('user_jenis_pengguna', $data)) {
            $j = $data['user_jenis_pengguna'];
            $updateData['user_jenis_pengguna'] = ($j !== null && $j !== '') ? (string) $j : null;
        }
        if (isset($data['is_active'])) {
            $updateData['is_active'] = $data['is_active'];
        }
        if (array_key_exists('notes', $data)) {
            $n = $data['notes'];
            $updateData['notes'] = ($n !== null && $n !== '') ? (string) $n : null;
        }
        if (! empty($data['password'])) {
            $updateData['password'] = Hash::make($data['password']);
        }

        if (array_key_exists('role_ids', $data)) {
            $user->roles()->sync($data['role_ids'] ?? []);
            $firstRole = ! empty($data['role_ids']) ? Role::find($data['role_ids'][0]) : null;
            $updateData['role'] = $firstRole?->name ?? $user->role;
        }
        if (array_key_exists('customer_ids', $data)) {
            $user->customers()->sync($data['customer_ids'] ?? []);
            $firstCustomer = ! empty($data['customer_ids']) ? Customer::find($data['customer_ids'][0]) : null;
            $updateData['customer_code'] = $firstCustomer?->customer_code;
        }

        $user->update($updateData);
        $user->refresh();

        $mgrLevel = UserLevel::normalize($user->user_level ?? UserLevel::USER);

        if (array_key_exists('managed_agent_ids', $data)) {
            if (! UserLevel::canHaveManagedAgents($mgrLevel)) {
                if (! empty($data['managed_agent_ids'])) {
                    return $this->sendError(422, 'VALIDATION_ERROR', 'Managed agents only apply to Level 0–3 users (not end users / Level 4)');
                }
            } else {
                $syncErr = $this->syncManagedAgentsForManager($user, $data['managed_agent_ids'] ?? [], $actor);
                if ($syncErr !== null) {
                    return $syncErr;
                }
            }
        }

        if (array_key_exists('customer_agent_assignments', $data)) {
            if (! UserLevel::canHaveManagedAgents($mgrLevel)) {
                if (! empty($data['customer_agent_assignments'])) {
                    return $this->sendError(422, 'VALIDATION_ERROR', 'Customer-specific agents only apply to Level 0–3 users');
                }
            } else {
                $syncErr = $this->syncCustomerAgentAssignments(
                    $user->fresh(),
                    $data['customer_agent_assignments'] ?? [],
                    $actor
                );
                if ($syncErr !== null) {
                    return $syncErr;
                }
            }
        }

        if (! UserLevel::canHaveManagedAgents($mgrLevel)) {
            $this->clearManagedAgentsForManager($user);
        }

        return $this->sendOk($this->formatUser($user->fresh()));
    }

    public function destroy(Request $request, int|string $id): JsonResponse
    {
        $idInt = (int) $id;
        if ($request->user()->id === $idInt) {
            return $this->sendError(400, 'SELF_DELETE', 'You cannot delete your own account');
        }

        $user = User::find($idInt);

        if (! $user) {
            return $this->sendError(404, 'NOT_FOUND', 'User not found');
        }

        if (! $this->hierarchy->canSeeUser($request->user(), $user)) {
            return $this->sendError(403, 'FORBIDDEN', 'You cannot delete this user');
        }

        $user->delete();

        return $this->sendOk(['success' => true]);
    }
}
