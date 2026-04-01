<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('contact_no', 50)->nullable()->after('customer_name');
            $table->string('email', 255)->nullable()->after('contact_no');
            $table->string('system_name', 50)->nullable()->after('email');
            $table->string('version', 50)->nullable()->after('system_name');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['contact_no', 'email', 'system_name', 'version']);
        });
    }
};
