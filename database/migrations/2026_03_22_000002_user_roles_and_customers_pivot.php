<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('customer_user');
        Schema::dropIfExists('role_user');

        Schema::create('role_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'role_id']);
        });

        Schema::create('customer_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'customer_id']);
        });

        // Migrate existing data (only if users still have role_id/customer_id)
        if (Schema::hasColumn('users', 'role_id')) {
            DB::table('users')->whereNotNull('role_id')->orderBy('id')->chunk(100, function ($users) {
                foreach ($users as $user) {
                    DB::table('role_user')->insertOrIgnore([
                        'user_id' => $user->id,
                        'role_id' => $user->role_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
        }

        if (Schema::hasColumn('users', 'customer_id')) {
            DB::table('users')->whereNotNull('customer_id')->orderBy('id')->chunk(100, function ($users) {
                foreach ($users as $user) {
                    DB::table('customer_user')->insertOrIgnore([
                        'user_id' => $user->id,
                        'customer_id' => $user->customer_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
        }

        if (Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['role_id']);
                $table->dropColumn('role_id');
            });
        }
        if (Schema::hasColumn('users', 'customer_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['customer_id']);
            });
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex(['customer_id']);
            });
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('customer_id');
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('role_id')->nullable()->after('role')->constrained('roles')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->after('user_level')->constrained('customers')->nullOnDelete();
        });

        foreach (DB::table('role_user')->get()->groupBy('user_id') as $userId => $rows) {
            $first = $rows->first();
            DB::table('users')->where('id', $userId)->update(['role_id' => $first->role_id]);
        }

        foreach (DB::table('customer_user')->get()->groupBy('user_id') as $userId => $rows) {
            $first = $rows->first();
            DB::table('users')->where('id', $userId)->update(['customer_id' => $first->customer_id]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('role_id')->references('id')->on('roles')->nullOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
        });

        Schema::dropIfExists('customer_user');
        Schema::dropIfExists('role_user');
    }
};
