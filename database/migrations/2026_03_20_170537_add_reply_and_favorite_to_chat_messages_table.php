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
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->foreignId('reply_to_message_id')->nullable()->after('citations')
                ->constrained('chat_messages')->nullOnDelete();
            $table->foreignId('reply_to_user_id')->nullable()->after('reply_to_message_id')
                ->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('chat_messages', function (Blueprint $table) {
            $table->dropForeign(['reply_to_message_id']);
            $table->dropForeign(['reply_to_user_id']);
        });
    }
};
