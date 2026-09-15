<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            // Shared VTEC identity -- populated for any VTEC-class row,
            // whether it arrived as text or CAP. vtec_office is deliberately
            // separate from the existing `cccc` column: `cccc` is KWBC (the
            // national gateway) for every CAP row, never the originating WFO,
            // so it can't be relied on for VTEC office identity.
            $table->string('vtec_office')->nullable()->after('risk_levels');
            $table->string('vtec_phenomenon')->nullable()->after('vtec_office');
            $table->string('vtec_significance')->nullable()->after('vtec_phenomenon');
            $table->string('vtec_event_number', 4)->nullable()->after('vtec_significance');
            $table->unsignedSmallInteger('vtec_year')->nullable()->after('vtec_event_number');
            $table->string('vtec_action')->nullable()->after('vtec_year');
            $table->timestamp('vtec_begin')->nullable()->after('vtec_action');
            $table->timestamp('vtec_end')->nullable()->after('vtec_begin');

            // CAP-only fields, null on every text row.
            $table->string('format')->default('text')->after('vtec_end');
            $table->string('msg_type')->nullable()->after('format');
            $table->string('event')->nullable()->after('msg_type');
            $table->string('severity')->nullable()->after('event');
            $table->string('urgency')->nullable()->after('severity');
            $table->string('certainty')->nullable()->after('urgency');
            $table->text('headline')->nullable()->after('certainty');
            $table->text('description')->nullable()->after('headline');
            $table->text('instruction')->nullable()->after('description');
            $table->string('event_motion_description')->nullable()->after('instruction');

            // Correlation: the specific paired text issuance this CAP
            // payload cross-references, via CAP's own AWIPSidentifier and
            // WMOidentifier parameters.
            $table->string('awips_identifier')->nullable()->after('event_motion_description');
            $table->string('wmo_identifier')->nullable()->after('awips_identifier');
        });
    }

    public function down(): void
    {
        Schema::table('ingested_products', function (Blueprint $table): void {
            $table->dropColumn([
                'vtec_office', 'vtec_phenomenon', 'vtec_significance', 'vtec_event_number',
                'vtec_year', 'vtec_action', 'vtec_begin', 'vtec_end',
                'format', 'msg_type', 'event', 'severity', 'urgency', 'certainty',
                'headline', 'description', 'instruction', 'event_motion_description',
                'awips_identifier', 'wmo_identifier',
            ]);
        });
    }
};
