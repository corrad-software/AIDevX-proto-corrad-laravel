<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_documents', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('original_filename');
            $table->string('file_path');
            $table->string('file_type')->default('docx');
            $table->bigInteger('file_size')->default(0);
            $table->string('module')->nullable();
            $table->string('openai_file_id')->nullable();
            $table->string('status')->default('pending'); // pending, uploaded, failed
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['module', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_documents');
    }
};
