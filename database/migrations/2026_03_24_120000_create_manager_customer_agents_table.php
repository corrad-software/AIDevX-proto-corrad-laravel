<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ejen yang dilantik bagi setiap pelanggan di bawah seorang pentadbir (L0–L3).
     */
    public function up(): void
    {
        Schema::create('manager_customer_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('manager_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignId('agent_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['manager_user_id', 'customer_id', 'agent_user_id'], 'mca_manager_customer_agent_unique');
            $table->index(['manager_user_id', 'customer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manager_customer_agents');
    }
};
