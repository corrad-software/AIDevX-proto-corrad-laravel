<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('managed_by_user_id')
                ->nullable()
                ->after('customer_code')
                ->constrained('users')
                ->nullOnDelete();

            $table->index('managed_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['managed_by_user_id']);
            $table->dropIndex(['managed_by_user_id']);
            $table->dropColumn('managed_by_user_id');
        });
    }
};
