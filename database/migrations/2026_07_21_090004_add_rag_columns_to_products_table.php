<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // sha256 of name+description+category+tags — reindex only when this changes, not on price/stock updates
            $table->string('content_hash', 64)->nullable()->after('ai_search_keywords');
            $table->uuid('qdrant_point_id')->nullable()->after('content_hash');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['content_hash', 'qdrant_point_id']);
        });
    }
};
