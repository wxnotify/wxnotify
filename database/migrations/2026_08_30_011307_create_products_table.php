<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('family');
            $table->boolean('has_polygon')->default(false);
            $table->json('risk_level_options')->nullable();
            $table->string('status')->default('active');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed catalog: real NWS VTEC-coded warnings/watches/advisories/statements,
        // the five SPC/WPC products, and the Area Forecast Discussion pseudo-product
        // (per the plan's DATA section). This is a starting catalog, not exhaustive —
        // NWS adds/retires products over time, and the admin Product Catalog tool
        // (a later phase) is the intended way to keep it current without a deploy.
        $now = now();
        $row = fn (string $code, string $name, string $family, bool $polygon = false, ?array $riskLevels = null): array => [
            'code' => $code,
            'name' => $name,
            'family' => $family,
            'has_polygon' => $polygon,
            'risk_level_options' => $riskLevels ? json_encode($riskLevels) : null,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        $products = [
            // Warnings
            $row('TO.W', 'Tornado Warning', 'warning', polygon: true),
            $row('SV.W', 'Severe Thunderstorm Warning', 'warning', polygon: true),
            $row('FF.W', 'Flash Flood Warning', 'warning', polygon: true),
            $row('FL.W', 'Flood Warning', 'warning'),
            $row('WS.W', 'Winter Storm Warning', 'warning'),
            $row('IS.W', 'Ice Storm Warning', 'warning'),
            $row('BZ.W', 'Blizzard Warning', 'warning'),
            $row('HW.W', 'High Wind Warning', 'warning'),
            $row('EW.W', 'Extreme Wind Warning', 'warning', polygon: true),
            $row('EH.W', 'Excessive Heat Warning', 'warning'),
            $row('FZ.W', 'Freeze Warning', 'warning'),
            $row('HU.W', 'Hurricane Warning', 'warning'),
            $row('TR.W', 'Tropical Storm Warning', 'warning'),
            $row('SS.W', 'Storm Surge Warning', 'warning'),
            $row('DS.W', 'Dust Storm Warning', 'warning', polygon: true),
            $row('SQ.W', 'Snow Squall Warning', 'warning', polygon: true),
            $row('CF.W', 'Coastal Flood Warning', 'warning'),
            $row('LE.W', 'Lake Effect Snow Warning', 'warning'),
            $row('WC.W', 'Wind Chill Warning', 'warning'),
            $row('FW.W', 'Red Flag Warning', 'warning'),

            // Watches
            $row('TO.A', 'Tornado Watch', 'watch'),
            $row('SV.A', 'Severe Thunderstorm Watch', 'watch'),
            $row('FF.A', 'Flash Flood Watch', 'watch'),
            $row('WS.A', 'Winter Storm Watch', 'watch'),
            $row('FA.A', 'Flood Watch', 'watch'),
            $row('FW.A', 'Fire Weather Watch', 'watch'),
            $row('EH.A', 'Excessive Heat Watch', 'watch'),
            $row('HU.A', 'Hurricane Watch', 'watch'),
            $row('TR.A', 'Tropical Storm Watch', 'watch'),
            $row('SS.A', 'Storm Surge Watch', 'watch'),
            $row('BZ.A', 'Blizzard Watch', 'watch'),

            // Advisories
            $row('WW.Y', 'Winter Weather Advisory', 'advisory'),
            $row('WI.Y', 'Wind Advisory', 'advisory'),
            $row('FG.Y', 'Dense Fog Advisory', 'advisory'),
            $row('FR.Y', 'Frost Advisory', 'advisory'),
            $row('HT.Y', 'Heat Advisory', 'advisory'),
            $row('WC.Y', 'Wind Chill Advisory', 'advisory'),
            $row('CF.Y', 'Coastal Flood Advisory', 'advisory'),
            $row('SC.Y', 'Small Craft Advisory', 'advisory'),
            $row('FA.Y', 'Flood Advisory', 'advisory'),

            // Statements
            $row('SPS', 'Special Weather Statement', 'statement', polygon: true),
            $row('SVS', 'Severe Weather Statement', 'statement', polygon: true),

            // SPC / WPC
            $row('SPC.OUTLOOK', 'SPC Convective Outlook', 'spc_wpc', riskLevels: ['Marginal', 'Slight', 'Enhanced', 'Moderate', 'High']),
            $row('SPC.FIRE', 'SPC Fire Weather Outlook', 'spc_wpc', riskLevels: ['Elevated', 'Critical', 'Extremely Critical', 'Isolated Dry T-Storms', 'Scattered Dry T-Storms']),
            $row('WPC.ERO', 'WPC Excessive Rainfall Outlook', 'spc_wpc', riskLevels: ['Marginal', 'Slight', 'Moderate', 'High']),
            $row('SPC.MD', 'SPC Mesoscale Discussion', 'spc_wpc', polygon: true),
            $row('WPC.MPD', 'WPC Mesoscale Precipitation Discussion', 'spc_wpc', polygon: true),

            // Forecast Discussion
            $row('AFD', 'Area Forecast Discussion', 'forecast_discussion'),
        ];

        foreach (array_values($products) as $index => $product) {
            $product['sort_order'] = $index + 1;
            DB::table('products')->insert($product);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
