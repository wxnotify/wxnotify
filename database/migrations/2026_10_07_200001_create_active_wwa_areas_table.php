<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per (event, zone): an NWS event is issued as many bulletins, one per zone group, and a
        // cancel or expiry usually ends only some of its zones, so the zones carry the state.
        Schema::create('active_wwa_areas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('active_wwa_id')->constrained()->cascadeOnDelete();
            $table->string('ugc_code');
            $table->string('status')->default('Active'); // Active | Cancelled | Expired
            $table->geometry('polygon', 'POLYGON', 4269)->nullable();
            $table->timestamp('effective')->nullable();
            $table->timestamp('expires')->nullable();
            $table->foreignId('latest_segment_id')->nullable()->constrained('ingested_product_segments')->nullOnDelete();
            // The receiving bulletin's received_at: a late, older bulletin must not undo a newer state.
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['active_wwa_id', 'ugc_code']);
            $table->index('ugc_code');
            $table->spatialIndex('polygon');
        });

        // Active events as they stand today become zone rows, so the readers are correct the moment
        // this deploys; active-wwas:rebuild then repairs the ones whose zones were already lost.
        DB::statement(<<<'SQL'
            INSERT INTO active_wwa_areas (active_wwa_id, ugc_code, status, polygon, effective, expires, applied_at, created_at, updated_at)
            SELECT w.id, z.code, 'Active', w.polygon, w.effective, w.expires, w.updated_at, now(), now()
            FROM active_wwas w
            CROSS JOIN LATERAL json_array_elements_text(w.ugc_codes) AS z(code)
            WHERE w.status = 'Active' AND w.ugc_codes IS NOT NULL AND json_typeof(w.ugc_codes) = 'array'
            ON CONFLICT (active_wwa_id, ugc_code) DO NOTHING
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('active_wwa_areas');
    }
};
