<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('user_level', 20)->default('user')->after('role_id');
            $table->foreignId('customer_id')->nullable()->after('user_level')->constrained('customers')->nullOnDelete();
            $table->string('customer_code', 50)->nullable()->after('customer_id');

            $table->index('user_level');
            $table->index('customer_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
            $table->dropColumn(['user_level', 'customer_id', 'customer_code']);
        });
    }
};
