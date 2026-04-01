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
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->string('session_type')->default('solo')->after('user_id');
            $table->string('desk365_ticket_id')->nullable()->after('session_type');
            $table->json('participant_ids')->nullable()->after('desk365_ticket_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_sessions', function (Blueprint $table) {
            $table->dropColumn(['session_type', 'desk365_ticket_id', 'participant_ids']);
        });
    }
};
