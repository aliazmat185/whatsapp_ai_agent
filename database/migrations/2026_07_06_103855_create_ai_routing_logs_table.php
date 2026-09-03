<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_routing_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('conversations')->cascadeOnDelete();
            $table->foreignId('conversation_message_id')->nullable()->constrained('conversation_messages')->nullOnDelete();
            $table->string('detected_intent')->nullable(); // product_search / store_selection / cart / checkout / order_status / support / unknown
            $table->foreignId('resolved_vendor_id')->nullable()->constrained('vendors')->nullOnDelete();
            $table->foreignId('resolved_store_id')->nullable()->constrained('stores')->nullOnDelete();
            $table->json('claude_request_payload')->nullable();
            $table->json('claude_response_payload')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->boolean('escalated')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conversation_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_routing_logs');
    }
};
