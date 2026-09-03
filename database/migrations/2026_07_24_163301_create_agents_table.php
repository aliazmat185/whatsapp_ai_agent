<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained('vendors')->cascadeOnDelete();

            $table->string('name');
            $table->text('persona')->nullable(); // tone/personality/instructions, free text
            $table->enum('sales_goal', ['faq_only', 'upsell', 'full_order_closing'])->default('full_order_closing');
            $table->string('language')->default('English');
            $table->text('escalation_rules')->nullable();

            // Only one agent per vendor is active at a time — mirrors the
            // one-WhatsApp-number-per-vendor topology (whatsapp_accounts.vendor_id
            // is unique), so "active" effectively means "bound to the vendor's number".
            $table->boolean('is_active')->default(false);

            $table->timestamps();

            $table->index(['vendor_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agents');
    }
};
