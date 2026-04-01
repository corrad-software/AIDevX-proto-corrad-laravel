<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_ticket_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('triggered_by', 50)->default('manual');
            $table->unsignedInteger('total_tickets')->default(0);
            $table->unsignedInteger('modules_synced')->default(0);
            $table->unsignedInteger('uploaded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 20)->default('success');
            $table->text('message')->nullable();
            $table->json('uploaded_modules')->nullable();
            $table->json('uploaded_ticket_numbers')->nullable();
            $table->json('uploaded_module_counts')->nullable();
            $table->json('uploaded_ticket_details')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_ticket_sync_logs');
    }
};
