<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->text('address_line');
            $table->string('city');
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->timestamps();

            $table->unique('store_id');
        });

        // `point` is generated from lat/lng and spatially indexed for
        // ST_Distance_Sphere nearest-store queries (see StoreLocatorService).
        // Laravel's schema builder doesn't support generated SPATIAL columns,
        // so this is raw SQL. SRID 4326 = standard WGS84 lat/lng.
        // MySQL only — sqlite (used in tests) has no spatial support, so
        // StoreLocatorService falls back to a PHP Haversine calculation
        // there (see its class doc).
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('
                ALTER TABLE store_locations
                ADD COLUMN point POINT
                    GENERATED ALWAYS AS (ST_SRID(POINT(longitude, latitude), 4326)) STORED NOT NULL SRID 4326,
                ADD SPATIAL INDEX store_locations_point_spatial (point)
            ');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_locations');
    }
};
