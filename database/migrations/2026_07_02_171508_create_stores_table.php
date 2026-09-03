<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_primary')->default(false); // fallback store when no nearby match
            $table->json('working_hours')->nullable(); // {"mon":{"open":"09:00","close":"21:00"}, ...}
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['vendor_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
