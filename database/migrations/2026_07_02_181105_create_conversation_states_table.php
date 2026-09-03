<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversation_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained('conversations')->cascadeOnDelete();
            $table->enum('current_step', [
                'greeting', 'awaiting_location', 'browsing', 'cart_review',
                'awaiting_payment_method', 'awaiting_confirmation', 'order_placed', 'support_escalation',
            ])->default('greeting');
            $table->json('context')->nullable(); // working memory: selected category, candidate store_ids, pending cart_id, etc.
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversation_states');
    }
};
