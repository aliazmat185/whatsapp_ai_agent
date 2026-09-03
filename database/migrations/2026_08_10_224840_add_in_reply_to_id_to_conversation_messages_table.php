<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->foreignId('in_reply_to_id')->nullable()->after('conversation_id')
                ->references('id')->on('conversation_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversation_messages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('in_reply_to_id');
        });
    }
};
