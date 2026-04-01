<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Rename legacy user_level "admin" to internal_admin (Level 1).
     * Runs after add_user_level migration.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'user_level')) {
            return;
        }
        DB::table('users')->where('user_level', 'admin')->update(['user_level' => 'internal_admin']);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'user_level')) {
            return;
        }
        DB::table('users')->where('user_level', 'internal_admin')->update(['user_level' => 'admin']);
    }
};
