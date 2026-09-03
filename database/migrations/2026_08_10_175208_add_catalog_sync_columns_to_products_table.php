<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('catalog_synced_at')->nullable()->after('qdrant_point_id');
            $table->string('catalog_sync_error')->nullable()->after('catalog_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['catalog_synced_at', 'catalog_sync_error']);
        });
    }
};
