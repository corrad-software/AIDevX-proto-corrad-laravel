<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('synced_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 100)->index();
            $table->string('subject')->nullable();
            $table->text('description')->nullable();
            $table->string('module', 100)->nullable()->index();
            $table->string('status', 50)->nullable();
            $table->string('type', 50)->nullable();
            $table->string('priority', 50)->nullable();
            $table->string('contact_name')->nullable();
            $table->string('company_name')->nullable();
            $table->string('created_time')->nullable();
            $table->string('assigned_agent')->nullable();
            $table->foreignId('desk365_sync_log_id')->nullable()->constrained('desk365_sync_logs')->nullOnDelete();
            $table->timestamps();

            $table->index(['ticket_number', 'module']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('synced_tickets');
    }
};
