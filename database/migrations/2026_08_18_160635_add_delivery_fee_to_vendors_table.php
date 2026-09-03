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
        Schema::table('vendors', function (Blueprint $table) {
            // Null means "use the platform default" (config('commerce.default_delivery_fee'))
            // — lets existing vendors keep today's behavior until they set their own.
            $table->decimal('delivery_fee', 10, 2)->nullable()->after('default_currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn('delivery_fee');
        });
    }
};
