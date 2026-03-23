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

        return match ($l) {
            'super_admin', 'superadmin', 'l0', 'level0' => self::SUPER_ADMIN,
            'admin', 'internal_admin', 'internaladmin', 'l1', 'level1' => self::INTERNAL_ADMIN,
            'external_admin', 'externaladmin', 'l2', 'level2' => self::EXTERNAL_ADMIN,
            'agent', 'l3', 'level3' => self::AGENT,
            'user', 'l4', 'level4', '4' => self::USER,
            default => in_array($raw, self::all(), true) ? $raw : self::USER,
        };
    }

    public static function numericTier(string $level): int
    {
        return match (self::normalize($level)) {
            self::SUPER_ADMIN => 0,
            self::INTERNAL_ADMIN => 1,
            self::EXTERNAL_ADMIN => 2,
            self::AGENT => 3,
            self::USER => 4,
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

    /** AINA (user chat): Level 4 only. */
    public static function canAccessUserChat(string $level): bool
    {
        return self::normalize($level) === self::USER;
    }

    /** See all Desk365 / open tickets (not agent-scoped). */
    public static function canSeeAllDeskTickets(string $level): bool
    {
        $l = self::normalize($level);

        return in_array($l, [self::SUPER_ADMIN, self::INTERNAL_ADMIN, self::EXTERNAL_ADMIN], true);
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
            self::SUPER_ADMIN => [self::SUPER_ADMIN, self::INTERNAL_ADMIN, self::EXTERNAL_ADMIN, self::AGENT, self::USER],
            self::INTERNAL_ADMIN => [self::EXTERNAL_ADMIN, self::AGENT, self::USER],
            self::EXTERNAL_ADMIN => [self::AGENT, self::USER],
            self::AGENT => [self::USER],
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
            self::EXTERNAL_ADMIN => in_array($t, [self::AGENT, self::USER], true),
            self::AGENT => $t === self::USER,
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
            self::SUPER_ADMIN => [self::INTERNAL_ADMIN, self::EXTERNAL_ADMIN, self::AGENT, self::USER],
            self::INTERNAL_ADMIN => [self::INTERNAL_ADMIN, self::EXTERNAL_ADMIN, self::AGENT, self::USER],
            self::EXTERNAL_ADMIN => [self::AGENT, self::USER],
            self::AGENT => [self::USER],
            default => [],
        };
    }

    public static function canImpersonate(string $level): bool
    {
        return self::impersonatableTargetLevels($level) !== [];
    }
}
