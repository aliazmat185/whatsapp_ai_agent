<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The store_locations table on this environment is missing its primary
     * key/auto-increment on `id`, its unique index on `store_id`, its
     * foreign key, and its spatial index — none of which are present even
     * though the original migration is marked as ran. This repairs the
     * table to match what create_store_locations_table intended.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE store_locations MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, ADD PRIMARY KEY (id)');
        DB::statement('ALTER TABLE store_locations ADD UNIQUE KEY store_locations_store_id_unique (store_id)');
        DB::statement('ALTER TABLE store_locations ADD CONSTRAINT store_locations_store_id_foreign FOREIGN KEY (store_id) REFERENCES stores (id) ON DELETE CASCADE');
        DB::statement('ALTER TABLE store_locations ADD SPATIAL INDEX store_locations_point_spatial (point)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE store_locations DROP INDEX store_locations_point_spatial');
        DB::statement('ALTER TABLE store_locations DROP FOREIGN KEY store_locations_store_id_foreign');
        DB::statement('ALTER TABLE store_locations DROP INDEX store_locations_store_id_unique');
        DB::statement('ALTER TABLE store_locations DROP PRIMARY KEY, MODIFY id BIGINT UNSIGNED NOT NULL');
    }
};
