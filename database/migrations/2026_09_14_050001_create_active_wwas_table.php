<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('active_wwas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            // Composite VTEC key -- this is the persistent event identity.
            $table->string('vtec_office');
            $table->string('vtec_phenomenon');
            $table->string('vtec_significance');
            $table->string('vtec_event_number', 4);
            $table->unsignedSmallInteger('vtec_year');

            $table->string('status')->default('Active'); // Active | Cancelled | Expired
            $table->geometry('polygon', 'POLYGON', 4269)->nullable();
            $table->json('ugc_codes')->nullable();
            $table->timestamp('effective')->nullable();
            $table->timestamp('expires')->nullable();
            $table->text('headline')->nullable();

            // Parsed from CAP's eventMotionDescription -- null until a CAP
            // payload enriches this row (no motion vector is ever parsed
            // from raw text).
            $table->unsignedSmallInteger('motion_direction_degrees')->nullable();
            $table->unsignedSmallInteger('motion_speed_kt')->nullable();
            $table->decimal('motion_reference_lat', 8, 5)->nullable();
            $table->decimal('motion_reference_lon', 8, 5)->nullable();
            $table->timestamp('motion_observed_at')->nullable();

            $table->string('source')->nullable(); // text | cap -- which extraction currently backs the fields above
            $table->foreignId('latest_ingested_product_id')->nullable()->constrained('ingested_products')->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number', 'vtec_year'],
                'active_wwas_vtec_key_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('active_wwas');
    }
};
