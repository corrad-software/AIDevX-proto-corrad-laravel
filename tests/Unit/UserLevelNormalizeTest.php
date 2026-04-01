<?php

namespace Tests\Unit;

use App\Enums\UserLevel;
use PHPUnit\Framework\TestCase;

class UserLevelNormalizeTest extends TestCase
{
    public function test_normalizes_case_insensitive_internal_admin(): void
    {
        $this->assertSame(UserLevel::INTERNAL_ADMIN, UserLevel::normalize('INTERNAL_ADMIN'));
        $this->assertSame(UserLevel::INTERNAL_ADMIN, UserLevel::normalize('internal_admin'));
    }

    public function test_legacy_admin_alias(): void
    {
        $this->assertSame(UserLevel::INTERNAL_ADMIN, UserLevel::normalize('admin'));
    }

    public function test_normalizes_internal_admin_with_spaces(): void
    {
        $this->assertSame(UserLevel::INTERNAL_ADMIN, UserLevel::normalize('internal admin'));
        $this->assertSame(UserLevel::INTERNAL_ADMIN, UserLevel::normalize('Level 1'));
    }

    public function test_normalizes_numeric_tier_strings(): void
    {
        $this->assertSame(UserLevel::INTERNAL_ADMIN, UserLevel::normalize('1'));
        $this->assertSame(UserLevel::EXTERNAL_ADMIN, UserLevel::normalize('2'));
        $this->assertSame(UserLevel::AGENT, UserLevel::normalize('3'));
        $this->assertSame(UserLevel::USER, UserLevel::normalize('4'));
        $this->assertSame(UserLevel::SECONDARY_USER, UserLevel::normalize('5'));
    }

    public function test_secondary_user_aliases(): void
    {
        $this->assertSame(UserLevel::SECONDARY_USER, UserLevel::normalize('secondary_user'));
        $this->assertSame(UserLevel::SECONDARY_USER, UserLevel::normalize('L5'));
        $this->assertTrue(UserLevel::isEndUserTier(UserLevel::SECONDARY_USER));
        $this->assertTrue(UserLevel::isEndUserTier(UserLevel::USER));
        $this->assertFalse(UserLevel::isEndUserTier(UserLevel::AGENT));
    }
}
