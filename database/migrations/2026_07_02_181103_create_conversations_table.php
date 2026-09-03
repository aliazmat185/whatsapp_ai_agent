<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();
            $table->foreignId('store_id')->nullable()->constrained('stores')->nullOnDelete(); // resolved once location/intent known (Phase 5)
            $table->string('customer_phone');
            $table->string('customer_name')->nullable();
            $table->decimal('customer_lat', 10, 7)->nullable();
            $table->decimal('customer_lng', 10, 7)->nullable();
            $table->enum('status', ['active', 'awaiting_customer', 'needs_attention', 'closed'])->default('active');
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();

            // Customer identity is (vendor_id, phone)-scoped, not global —
            // same phone messaging two different vendors gets two rows.
            $table->unique(['vendor_id', 'customer_phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversations');
    }
};
