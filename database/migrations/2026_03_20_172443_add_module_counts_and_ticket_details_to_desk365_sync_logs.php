<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('desk365_sync_logs', function (Blueprint $table) {
            $table->json('uploaded_module_counts')->nullable()->after('uploaded_ticket_numbers');
            $table->json('uploaded_ticket_details')->nullable()->after('uploaded_module_counts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('desk365_sync_logs', function (Blueprint $table) {
            $table->dropColumn(['uploaded_module_counts', 'uploaded_ticket_details']);
        });
    }
};
