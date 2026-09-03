<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete(); // denormalized for fast scoping
            $table->foreignId('knowledge_base_id')->constrained('knowledge_bases')->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('disk_path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('source_hash', 64); // sha256 of file contents, dedupe/versioning
            $table->string('status')->default('pending'); // pending/parsing/chunking/embedding/ready/failed
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['vendor_id', 'status']);
            $table->unique(['knowledge_base_id', 'source_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
