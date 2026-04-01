<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->boolean('ai_assistance_enabled')->default(true);
            $table->boolean('ai_awaiting_satisfaction')->default(false);
        });

        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->boolean('is_ai_message')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('support_ticket_messages', function (Blueprint $table) {
            $table->dropColumn('is_ai_message');
        });

        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropColumn(['ai_assistance_enabled', 'ai_awaiting_satisfaction']);
        });
    }
};
