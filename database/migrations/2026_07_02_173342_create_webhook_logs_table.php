<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('source', ['whatsapp', 'payment_gateway'])->default('whatsapp');
            $table->string('wa_message_id')->nullable()->index(); // idempotency check point
            $table->json('raw_payload');
            $table->enum('processing_status', ['received', 'processed', 'ignored_duplicate', 'failed'])->default('received');
            $table->text('error_message')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_logs');
    }
};
