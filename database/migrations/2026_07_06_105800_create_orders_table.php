<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number')->unique(); // e.g. ORD-20260706-0001
            $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
            $table->foreignId('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignId('conversation_id')->constrained('conversations')->restrictOnDelete();
            $table->foreignId('cart_id')->constrained('carts')->restrictOnDelete();

            $table->string('customer_phone');
            $table->string('customer_name')->nullable();
            $table->text('customer_address')->nullable();
            $table->decimal('customer_lat', 10, 7)->nullable();
            $table->decimal('customer_lng', 10, 7)->nullable();

            $table->decimal('subtotal', 10, 2);
            $table->decimal('delivery_fee', 10, 2)->default(0);
            $table->decimal('total', 10, 2);

            // Payment gateway integration lands in Phase 7 — these columns
            // just record the customer's choice and its lifecycle for now.
            $table->enum('payment_method', ['cod', 'jazzcash', 'easypaisa', 'card']);
            $table->enum('payment_status', ['pending', 'paid', 'failed', 'refunded'])->default('pending');

            $table->enum('status', ['pending', 'confirmed', 'preparing', 'out_for_delivery', 'completed', 'cancelled'])->default('pending');
            $table->boolean('needs_attention')->default(false);
            $table->text('cancelled_reason')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['vendor_id', 'status']);
            $table->index(['store_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
