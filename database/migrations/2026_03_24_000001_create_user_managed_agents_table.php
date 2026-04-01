<?php

use App\Enums\UserLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Explicit assignment rows (mirrors users.managed_by_user_id for agents) — same idea as customer_user.
     */
    public function up(): void
    {
        Schema::create('user_managed_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('agent_user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index('manager_user_id');
        });

        if (Schema::hasTable('users') && Schema::hasColumn('users', 'managed_by_user_id')) {
            $now = now();
            $rows = DB::table('users')
                ->where('user_level', UserLevel::AGENT)
                ->whereNotNull('managed_by_user_id')
                ->orderBy('id')
                ->get(['id', 'managed_by_user_id']);

            foreach ($rows as $row) {
                DB::table('user_managed_agents')->insertOrIgnore([
                    'manager_user_id' => $row->managed_by_user_id,
                    'agent_user_id' => $row->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_managed_agents');
    }
};
