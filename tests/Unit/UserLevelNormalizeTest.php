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
}
