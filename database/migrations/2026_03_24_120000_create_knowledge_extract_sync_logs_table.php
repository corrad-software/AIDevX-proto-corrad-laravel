<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_extract_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('section', 50);
            $table->string('triggered_by', 50)->default('api');
            $table->string('status', 20)->default('success');
            $table->text('message')->nullable();
            $table->longText('output')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['section', 'created_at']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_extract_sync_logs');
    }
};
