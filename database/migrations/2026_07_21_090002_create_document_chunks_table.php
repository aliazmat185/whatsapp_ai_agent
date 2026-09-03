<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete(); // denormalized for fast scoping/cleanup
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->unsignedInteger('token_count');
            $table->uuid('qdrant_point_id')->nullable(); // null until embedded; vector itself lives only in Qdrant
            $table->json('metadata')->nullable(); // e.g. {page, section}
            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
            $table->index('qdrant_point_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
    }
};
