<?php

namespace App\Enums;

/**
 * User hierarchy for AFSA (admin suite), SELAR (support), AINA (user chat), and future Ticket 365.
 *
 * @see docs/user-levels-asfa-ticketing.md
 */
class UserLevel
{
    /** Level 0 — Super Admin (system developers). */
    public const SUPER_ADMIN = 'super_admin';

    /** Level 1 — Internal admin (pegawai pentadbir dalaman). */
    public const INTERNAL_ADMIN = 'internal_admin';

    /** Level 2 — External admin (pentadbir luaran, dilantik oleh Level 1). */
    public const EXTERNAL_ADMIN = 'external_admin';

    /** Level 3 — Agent (ejen dalaman/luaran; dilantik oleh Level 1 atau 2). */
    public const AGENT = 'agent';

    /** Level 4 — User / requestor (help desk, AINA). */
    public const USER = 'user';

    /** Level 5 — Secondary / extended end user (lookup code 5; same class as L4 for tickets, AINA, hierarchy). */
    public const SECONDARY_USER = 'secondary_user';

    /**
     * @deprecated Legacy DB value; migrated to internal_admin. Do not write this value.
     */
    public const ADMIN = 'admin';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::SUPER_ADMIN,
            self::INTERNAL_ADMIN,
            self::EXTERNAL_ADMIN,
            self::AGENT,
            self::USER,
        ];
    }

    /**
     * Raw `users.user_level` values to treat as Level 3 (agent) in SQL `whereIn`.
     * Wider than {@see AGENT} alone — imports / legacy rows may store `l3` or `level3`
     * (see {@see normalize()}), which would otherwise be excluded from picklists.
     *
     * @return list<string>
     */
    public static function agentStoredValues(): array
    {
        return [
            self::AGENT,
            'l3',
            'L3',
            'level3',
            'Level3',
            'LEVEL3',
        ];
    }

    public static function normalize(?string $level): string
    {
        $raw = $level ?? self::USER;
        if ($raw === '') {
            return self::USER;
        }

        // Exact legacy alias
        if ($raw === self::ADMIN) {
            return self::INTERNAL_ADMIN;
        }

        // Case-insensitive + common aliases (DB / imports may vary)
        $l = strtolower(trim((string) $raw));
        // Collapse spaces/hyphens so values like "internal admin" or "Level 1" map correctly.
        $l = (string) preg_replace('/[\s\-]+/', '_', $l);
        $l = (string) preg_replace('/_+/', '_', $l);
        $l = trim($l, '_');

        return match ($l) {
            'super_admin', 'superadmin', 'l0', 'level0', 'level_0' => self::SUPER_ADMIN,
            'admin', 'internal_admin', 'internaladmin', 'l1', 'level1', 'level_1' => self::INTERNAL_ADMIN,
            'external_admin', 'externaladmin', 'l2', 'level2', 'level_2' => self::EXTERNAL_ADMIN,
            'agent', 'l3', 'level3', 'level_3' => self::AGENT,
            'user', 'l4', 'level4', 'level_4' => self::USER,
            'secondary_user', 'secondaryuser', 'l5', 'level5', 'level_5', '2nd_level_user', 'second_level_user' => self::SECONDARY_USER,
            '0', '00' => self::SUPER_ADMIN,
            '1', '01' => self::INTERNAL_ADMIN,
            '2', '02' => self::EXTERNAL_ADMIN,
            '3', '03' => self::AGENT,
            '4', '04' => self::USER,
            '5', '05' => self::SECONDARY_USER,
            default => in_array($raw, self::all(), true) ? $raw : self::USER,
        };
    }

    /** Level 4–5: requestors / AINA / own-ticket scope (not staff). */
    public static function isEndUserTier(?string $level): bool
    {
        $n = self::normalize($level);

        return $n === self::USER || $n === self::SECONDARY_USER;
    }

    public static function numericTier(string $level): int
    {
        return match (self::normalize($level)) {
            self::SUPER_ADMIN => 0,
            self::INTERNAL_ADMIN => 1,
            self::EXTERNAL_ADMIN => 2,
            self::AGENT => 3,
            self::USER => 4,
            self::SECONDARY_USER => 5,
            default => 4,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::SUPER_ADMIN => 'Level 0 — Super Admin (Developer)',
            self::INTERNAL_ADMIN => 'Level 1 — Pentadbir dalaman',
            self::EXTERNAL_ADMIN => 'Level 2 — Pentadbir luaran',
            self::AGENT => 'Level 3 — Ejen',
            self::USER => 'Level 4 — Pengguna / pemohon',
            self::SECONDARY_USER => 'Level 5 — Pengguna peringkat kedua',
        ];
    }

    public static function label(string $level): string
    {
        $n = self::normalize($level);

        return self::labels()[$n] ?? $level;
    }

    /** SELAR / staff ticket views: Level 0–3. */
    public static function canAccessSupportChat(string $level): bool
    {
        $l = self::normalize($level);

        return in_array($l, [self::SUPER_ADMIN, self::INTERNAL_ADMIN, self::EXTERNAL_ADMIN, self::AGENT], true);
    }

    /** AINA (user chat): Level 4–5 (end users). */
    public static function canAccessUserChat(string $level): bool
    {
        return self::isEndUserTier($level);
    }

    /** See all Desk365 / open tickets (not agent-scoped). */
    public static function canSeeAllDeskTickets(string $level): bool
    {
        $l = self::normalize($level);

        return in_array($l, [self::SUPER_ADMIN, self::INTERNAL_ADMIN, self::EXTERNAL_ADMIN], true);
    }

    /**
     * Level 0–3 (all staff) may have agents assigned under them via `users.managed_by_user_id`
     * + `user_managed_agents` (UI: create/edit user). Level 4 (end user) cannot.
     */
    public static function canHaveManagedAgents(?string $level): bool
    {
        $l = self::normalize($level ?? self::USER);

        return in_array($l, [self::SUPER_ADMIN, self::INTERNAL_ADMIN, self::EXTERNAL_ADMIN, self::AGENT], true);
    }

    /**
     * Levels the actor may assign when creating or updating a user.
     *
     * @return list<string>
     */
    public static function assignableLevelsForActor(?string $actorLevel): array
    {
        $a = self::normalize($actorLevel ?? self::USER);

        return match ($a) {
            self::SUPER_ADMIN => [self::SUPER_ADMIN, self::INTERNAL_ADMIN, self::EXTERNAL_ADMIN, self::AGENT, self::USER, self::SECONDARY_USER],
            self::INTERNAL_ADMIN => [self::EXTERNAL_ADMIN, self::AGENT, self::USER, self::SECONDARY_USER],
            self::EXTERNAL_ADMIN => [self::AGENT, self::USER, self::SECONDARY_USER],
            self::AGENT => [self::USER, self::SECONDARY_USER],
            default => [],
        };
    }

    public static function actorCanAssignLevel(?string $actorLevel, string $targetLevel): bool
    {
        $t = self::normalize($targetLevel);

        return in_array($t, self::assignableLevelsForActor($actorLevel), true);
    }

    public static function actorCanEditTargetUser(?string $actorLevel, string $targetUserLevel): bool
    {
        $a = self::normalize($actorLevel ?? self::USER);
        $t = self::normalize($targetUserLevel);

        return match ($a) {
            self::SUPER_ADMIN => true,
            self::INTERNAL_ADMIN => $t !== self::SUPER_ADMIN,
            self::EXTERNAL_ADMIN => in_array($t, [self::AGENT, self::USER, self::SECONDARY_USER], true),
            self::AGENT => in_array($t, [self::USER, self::SECONDARY_USER], true),
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function impersonatableTargetLevels(string $actorLevel): array
    {
        $a = self::normalize($actorLevel);

        return match ($a) {
            self::SUPER_ADMIN => [self::INTERNAL_ADMIN, self::EXTERNAL_ADMIN, self::AGENT, self::USER, self::SECONDARY_USER],
            self::INTERNAL_ADMIN => [self::INTERNAL_ADMIN, self::EXTERNAL_ADMIN, self::AGENT, self::USER, self::SECONDARY_USER],
            self::EXTERNAL_ADMIN => [self::AGENT, self::USER, self::SECONDARY_USER],
            self::AGENT => [self::USER, self::SECONDARY_USER],
            default => [],
        };
    }

    public static function canImpersonateTarget(string $actorLevel, string $targetUserLevel): bool
    {
        $t = self::normalize($targetUserLevel);

        return in_array($t, self::impersonatableTargetLevels($actorLevel), true);
    }

    public static function canImpersonate(string $level): bool
    {
        return self::impersonatableTargetLevels($level) !== [];
    }
}
