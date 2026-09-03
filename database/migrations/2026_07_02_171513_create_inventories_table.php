<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            $table->integer('quantity')->default(0);
            $table->unsignedInteger('low_stock_threshold')->default(5);
            $table->boolean('track_stock')->default(true); // false = always available (e.g. services)
            $table->timestamps();

            // one stock row per product (or product+variant) per store
            $table->unique(['store_id', 'product_id', 'product_variant_id'], 'inventories_store_product_variant_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventories');
    }
};
