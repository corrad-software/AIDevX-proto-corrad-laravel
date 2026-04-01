<?php

namespace App\Services;

use App\Enums\UserLevel;
use App\Models\User;

class UserHierarchyService
{
    /**
     * Return all descendant user IDs in hierarchy (BFS by managed_by_user_id).
     *
     * @return list<int>
     */
    public function descendantIds(int $rootUserId): array
    {
        $all = [];
        $frontier = [$rootUserId];

        while (! empty($frontier)) {
            $children = User::query()
                ->whereIn('managed_by_user_id', $frontier)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $children = array_values(array_diff($children, $all));
            if ($children === []) {
                break;
            }

            $all = array_values(array_unique(array_merge($all, $children)));
            $frontier = $children;
        }

        return $all;
    }

    /**
     * Visible users for actor based on hierarchy.
     *
     * @return list<int>
     */
    public function visibleUserIdsFor(User $actor, bool $includeSelf = true): array
    {
        $level = UserLevel::normalize($actor->user_level ?? UserLevel::USER);
        if ($level === UserLevel::SUPER_ADMIN) {
            $ids = User::query()->pluck('id')->map(fn ($id) => (int) $id)->all();

            return $ids;
        }

        $ids = $this->descendantIds((int) $actor->id);
        if ($includeSelf) {
            $ids[] = (int) $actor->id;
        }

        return array_values(array_unique($ids));
    }

    public function canSeeUser(User $actor, User $target): bool
    {
        $visible = $this->visibleUserIdsFor($actor, true);

        return in_array((int) $target->id, $visible, true);
    }

    /**
     * User IDs with {@see UserLevel::AGENT} that the actor may assign support tickets to.
     *
     * Expands beyond strict `visibleUserIds` so Level 2/3 still see agents they manage or
     * share a manager with (common when agents report to Level 1, not to Level 2).
     *
     * @return list<int>
     */
    public function assignableAgentIdsForTicket(User $actor): array
    {
        $level = UserLevel::normalize($actor->user_level ?? UserLevel::USER);

        if (in_array($level, [UserLevel::SUPER_ADMIN, UserLevel::INTERNAL_ADMIN], true)) {
            return User::query()
                ->whereUserLevelIsAgent()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        $visibleIds = $this->visibleUserIdsFor($actor, true);
        $base = User::query()->whereUserLevelIsAgent();

        $fromVisible = $visibleIds !== []
            ? (clone $base)->whereIn('id', $visibleIds)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];

        $directReports = (clone $base)->where('managed_by_user_id', $actor->id)->pluck('id')->map(fn ($id) => (int) $id)->all();

        // Peers under the same manager. If the actor is linked to customer(s), restrict peers to those
        // who share a customer (stops "all org agents" when many users share one MAIPs-style customer).
        $siblingAgents = [];
        if ($actor->managed_by_user_id !== null) {
            $sib = (clone $base)
                ->where('managed_by_user_id', $actor->managed_by_user_id)
                ->where('id', '!=', $actor->id);
            $actorCustomerIds = $actor->customers()->pluck('customers.id')->all();
            if ($actorCustomerIds !== []) {
                $sib->whereHas('customers', function ($q) use ($actorCustomerIds) {
                    $q->whereIn('customers.id', $actorCustomerIds);
                });
            }
            $siblingAgents = $sib->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $merged = array_values(array_unique(array_merge($fromVisible, $directReports, $siblingAgents)));

        if (UserLevel::isEndUserTier($level)) {
            $customerIds = $actor->customers()->pluck('customers.id')->all();
            if ($customerIds !== [] && $actor->managed_by_user_id !== null) {
                $byCustomer = User::query()
                    ->whereUserLevelIsAgent()
                    ->where('managed_by_user_id', $actor->managed_by_user_id)
                    ->whereHas('customers', function ($q) use ($customerIds) {
                        $q->whereIn('customers.id', $customerIds);
                    })
                    ->pluck('id')
                    ->map(fn ($id) => (int) $id)
                    ->all();
                $merged = array_values(array_unique(array_merge($merged, $byCustomer)));
            }
        }

        return $merged;
    }

    /**
     * Broader agent IDs for @mentions on ticket replies (in-app notifications).
     * Typically all agents under the same L1 manager for L3/L4; full list for L0/L1.
     *
     * @return list<int>
     */
    public function mentionableAgentIdsForTicket(User $actor): array
    {
        $level = UserLevel::normalize($actor->user_level ?? UserLevel::USER);

        if (in_array($level, [UserLevel::SUPER_ADMIN, UserLevel::INTERNAL_ADMIN], true)) {
            return User::query()
                ->whereUserLevelIsAgent()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->values()
                ->all();
        }

        if ($level === UserLevel::EXTERNAL_ADMIN) {
            return $this->assignableAgentIdsForTicket($actor);
        }

        $base = User::query()->whereUserLevelIsAgent();

        if ($level === UserLevel::AGENT && $actor->managed_by_user_id !== null) {
            return (clone $base)
                ->where('managed_by_user_id', $actor->managed_by_user_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if (UserLevel::isEndUserTier($level) && $actor->managed_by_user_id !== null) {
            return (clone $base)
                ->where('managed_by_user_id', $actor->managed_by_user_id)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        return $this->assignableAgentIdsForTicket($actor);
    }
}
