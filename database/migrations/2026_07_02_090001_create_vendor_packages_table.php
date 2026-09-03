<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_packages', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->decimal('price', 10, 2);
            $table->enum('billing_cycle', ['monthly', 'yearly']);

            // -1 = unlimited
            $table->integer('max_stores')->default(1);
            $table->integer('max_products')->default(50);
            $table->integer('max_staff_users')->default(1);
            $table->integer('max_whatsapp_numbers')->default(1);
            $table->integer('max_orders_per_month')->default(-1);

            $table->boolean('ai_features_enabled')->default(true);
            $table->boolean('analytics_access')->default(true);

            // e.g. ["cod","jazzcash"] — allowed payment methods for this tier
            $table->json('enabled_payment_methods')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_packages');
    }
};
