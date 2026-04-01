<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('desk365_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('triggered_by', 50)->default('manual'); // manual, scheduler, api
            $table->unsignedInteger('total_tickets')->default(0);
            $table->unsignedInteger('modules_synced')->default(0);
            $table->unsignedInteger('uploaded')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 20)->default('success'); // success, failed
            $table->text('message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('created_at');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('desk365_sync_logs');
    }
};
