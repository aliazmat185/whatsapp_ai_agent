<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // Long-term memory: rolling summary of everything before
            // summarized_up_to_message_id, refreshed by SummarizeConversationJob
            // every N messages instead of replaying full history (RAG_PLAN.md).
            $table->text('summary')->nullable()->after('last_message_at');
            $table->foreignId('summarized_up_to_message_id')->nullable()
                ->after('summary')
                ->constrained('conversation_messages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('summarized_up_to_message_id');
            $table->dropColumn('summary');
        });
    }
};
