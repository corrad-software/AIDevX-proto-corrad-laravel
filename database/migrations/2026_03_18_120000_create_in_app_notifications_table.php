<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_app_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('notification_type', 32); // system | user
            $table->string('module', 64)->nullable(); // ticket, chat, auth, admin, etc.
            $table->string('event_key', 64)->nullable(); // ticket.new, user.created, chat.group_invite
            $table->string('title');
            $table->text('body')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('email_sent_at')->nullable();
            $table->string('email_status', 32)->default('pending'); // pending, sent, failed, skipped
            $table->text('email_error')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'read_at', 'created_at']);
            $table->index(['notification_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notifications');
    }
};
