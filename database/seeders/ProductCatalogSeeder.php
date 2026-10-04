<?php

namespace Database\Seeders;

use App\Enums\ProductCategory;
use App\Enums\ProductFamily;
use App\Enums\ProductStatus;
use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

/**
 * Merges the legacy WxNotify product catalog (app_products / app_product_categories,
 * copied into import/) into the products table. For each of the 152 legacy products:
 *
 *  - If it already exists in the current catalog (matched by phenomenon/tier, not by
 *    literal code — the legacy `abbr` scheme and this app's dotted VTEC-style `code`
 *    scheme differ), the existing row's code/name/family/status/sort_order are left
 *    untouched, and only category/description/example/wea_warning are filled in.
 *  - Otherwise, it's inserted as a new product using the legacy `abbr` as-is for `code`.
 *
 * Seven existing products have no legacy counterpart at all (Storm Surge Watch/Warning,
 * Lake Effect Snow Warning, Wind Chill Warning/Advisory, WPC Excessive Rainfall Outlook)
 * and are removed, per an explicit decision to match the legacy catalog's exact count.
 *
 * Re-running this seeder is safe: matched rows are re-updated and new rows use
 * updateOrCreate keyed on `code`, so nothing is duplicated.
 */
class ProductCatalogSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Existing products with no counterpart anywhere in the legacy catalog.
     *
     * @var list<string>
     */
    private const ORPHAN_CODES = ['BZ.A', 'LE.W', 'SS.A', 'SS.W', 'WC.W', 'WC.Y', 'WPC.ERO'];

    /**
     * Legacy abbr => the existing product `code` it should be merged into.
     *
     * @var array<string, array{code: string, category: ProductCategory, wea_warning: bool}>
     */
    private const MATCHED = [
        'TOW' => ['code' => 'TO.W', 'category' => ProductCategory::TornadoAndSevereThunderstorm, 'wea_warning' => true],
        'TOA' => ['code' => 'TO.A', 'category' => ProductCategory::TornadoAndSevereThunderstorm, 'wea_warning' => false],
        'SVW' => ['code' => 'SV.W', 'category' => ProductCategory::TornadoAndSevereThunderstorm, 'wea_warning' => false],
        'SVA' => ['code' => 'SV.A', 'category' => ProductCategory::TornadoAndSevereThunderstorm, 'wea_warning' => false],
        'SVS' => ['code' => 'SVS', 'category' => ProductCategory::TornadoAndSevereThunderstorm, 'wea_warning' => false],
        'SPS' => ['code' => 'SPS', 'category' => ProductCategory::TornadoAndSevereThunderstorm, 'wea_warning' => false],
        'FFW' => ['code' => 'FF.W', 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => true],
        'FFA' => ['code' => 'FF.A', 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'FAA' => ['code' => 'FA.A', 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'FLW' => ['code' => 'FL.W', 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'FAY' => ['code' => 'FA.Y', 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'FFG' => ['code' => 'WPC.MPD', 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'WSW' => ['code' => 'WS.W', 'category' => ProductCategory::WinterWeather, 'wea_warning' => false],
        'WSA' => ['code' => 'WS.A', 'category' => ProductCategory::WinterWeather, 'wea_warning' => false],
        'WWY' => ['code' => 'WW.Y', 'category' => ProductCategory::WinterWeather, 'wea_warning' => false],
        'BZW' => ['code' => 'BZ.W', 'category' => ProductCategory::WinterWeather, 'wea_warning' => false],
        'SQW' => ['code' => 'SQ.W', 'category' => ProductCategory::WinterWeather, 'wea_warning' => false],
        'ISW' => ['code' => 'IS.W', 'category' => ProductCategory::WinterWeather, 'wea_warning' => false],
        'FRY' => ['code' => 'FR.Y', 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'FZW' => ['code' => 'FZ.W', 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'XHW' => ['code' => 'EH.W', 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'XHA' => ['code' => 'EH.A', 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'HTY' => ['code' => 'HT.Y', 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'FGY' => ['code' => 'FG.Y', 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'HWW' => ['code' => 'HW.W', 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'WIY' => ['code' => 'WI.Y', 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'DSW' => ['code' => 'DS.W', 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'FWW' => ['code' => 'FW.W', 'category' => ProductCategory::FireWeather, 'wea_warning' => false],
        'FWA' => ['code' => 'FW.A', 'category' => ProductCategory::FireWeather, 'wea_warning' => false],
        'FD1' => ['code' => 'SPC.FIRE', 'category' => ProductCategory::FireWeather, 'wea_warning' => false],
        'HUW' => ['code' => 'HU.W', 'category' => ProductCategory::HurricaneAndTropicalStorm, 'wea_warning' => true],
        'HUA' => ['code' => 'HU.A', 'category' => ProductCategory::HurricaneAndTropicalStorm, 'wea_warning' => false],
        'TRW' => ['code' => 'TR.W', 'category' => ProductCategory::HurricaneAndTropicalStorm, 'wea_warning' => true],
        'TRA' => ['code' => 'TR.A', 'category' => ProductCategory::HurricaneAndTropicalStorm, 'wea_warning' => false],
        'EWW' => ['code' => 'EW.W', 'category' => ProductCategory::HurricaneAndTropicalStorm, 'wea_warning' => true],
        'CFW' => ['code' => 'CF.W', 'category' => ProductCategory::Coastal, 'wea_warning' => false],
        'CFY' => ['code' => 'CF.Y', 'category' => ProductCategory::Coastal, 'wea_warning' => false],
        'SCY' => ['code' => 'SC.Y', 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'AFD' => ['code' => 'AFD', 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'DY1' => ['code' => 'SPC.OUTLOOK', 'category' => ProductCategory::ConvectiveOutlooks, 'wea_warning' => false],
        'SWO' => ['code' => 'SPC.MD', 'category' => ProductCategory::OtherDiscussionsReportsAndOutlooks, 'wea_warning' => false],
    ];

    /**
     * Legacy abbr => new product data, used verbatim as the new `code`.
     *
     * @var array<string, array{name: string, family: ProductFamily, category: ProductCategory, wea_warning: bool}>
     */
    private const NEW = [
        'FFS' => ['name' => 'Flash Flood Statement', 'family' => ProductFamily::Statement, 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'FAW' => ['name' => 'Areal Flood Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'FLY' => ['name' => 'River Flood Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'FLA' => ['name' => 'Areal / River Flood Watch', 'family' => ProductFamily::Watch, 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'FLS' => ['name' => 'Areal / River Flood Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'AHD' => ['name' => 'Area Hydrological Discussion', 'family' => ProductFamily::Informational, 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'RVA' => ['name' => 'Hydrologic Summary', 'family' => ProductFamily::Informational, 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'HMD' => ['name' => 'Rainfall and Flood Outlook Product', 'family' => ProductFamily::Informational, 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'RRM' => ['name' => 'Rainfall Storm Total', 'family' => ProductFamily::Informational, 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'HYD' => ['name' => 'Daily Hydrometeorological Products', 'family' => ProductFamily::Informational, 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'RVF' => ['name' => 'River Forecast', 'family' => ProductFamily::Informational, 'category' => ProductCategory::FloodingAndHydrological, 'wea_warning' => false],
        'BWY' => ['name' => 'Brisk Wind Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'FZA' => ['name' => 'Freeze Watch', 'family' => ProductFamily::Watch, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'ECW' => ['name' => 'Extreme Cold Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'ECA' => ['name' => 'Extreme Cold Watch', 'family' => ProductFamily::Watch, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'CWY' => ['name' => 'Cold Weather Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'ZFY' => ['name' => 'Freezing Fog Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'HWA' => ['name' => 'High Wind Watch', 'family' => ProductFamily::Watch, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'DUY' => ['name' => 'Blowing Dust Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'LWY' => ['name' => 'Lake Wind Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'ASY' => ['name' => 'Air Stagnation Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'SMY' => ['name' => 'Dense Smoke Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'AFW' => ['name' => 'Ashfall Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'AFY' => ['name' => 'Ashfall Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'AQA' => ['name' => 'Air Quality Alert', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::NonPrecipitation, 'wea_warning' => false],
        'FRW' => ['name' => 'Fire Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::FireWeather, 'wea_warning' => false],
        'RFD' => ['name' => 'Grassland Fire Danger (Experimental)', 'family' => ProductFamily::Informational, 'category' => ProductCategory::FireWeather, 'wea_warning' => false],
        'FWF' => ['name' => 'Fire Weather Planning Forecast', 'family' => ProductFamily::Informational, 'category' => ProductCategory::FireWeather, 'wea_warning' => false],
        'FWS' => ['name' => 'Fire Weather Spot Forecast', 'family' => ProductFamily::Informational, 'category' => ProductCategory::FireWeather, 'wea_warning' => false],
        'FD2' => ['name' => 'SPC Day 2 Fire Weather Outlook Discussion', 'family' => ProductFamily::SpcWpc, 'category' => ProductCategory::FireWeather, 'wea_warning' => false],
        'F38' => ['name' => 'SPC Day 3-8 Fire Weather Outlook Discussion', 'family' => ProductFamily::SpcWpc, 'category' => ProductCategory::FireWeather, 'wea_warning' => false],
        'SMF' => ['name' => 'Smoke Management Weather Forecast', 'family' => ProductFamily::Informational, 'category' => ProductCategory::FireWeather, 'wea_warning' => false],
        'HLS' => ['name' => 'Hurricane Local Statement', 'family' => ProductFamily::Statement, 'category' => ProductCategory::HurricaneAndTropicalStorm, 'wea_warning' => false],
        'TYW' => ['name' => 'Typhoon Warning (Guam only)', 'family' => ProductFamily::Warning, 'category' => ProductCategory::HurricaneAndTropicalStorm, 'wea_warning' => false],
        'TYA' => ['name' => 'Typhoon Watch (Guam only)', 'family' => ProductFamily::Watch, 'category' => ProductCategory::HurricaneAndTropicalStorm, 'wea_warning' => false],
        'HFW' => ['name' => 'Hurricane Force Wind Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::HurricaneAndTropicalStorm, 'wea_warning' => false],
        'HFA' => ['name' => 'Hurricane Force Wind Watch', 'family' => ProductFamily::Watch, 'category' => ProductCategory::HurricaneAndTropicalStorm, 'wea_warning' => false],
        'CFA' => ['name' => 'Coastal Flood Watch', 'family' => ProductFamily::Watch, 'category' => ProductCategory::Coastal, 'wea_warning' => false],
        'LSW' => ['name' => 'Lakeshore Flood Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::Coastal, 'wea_warning' => false],
        'LSA' => ['name' => 'Lakeshore Flood Watch', 'family' => ProductFamily::Watch, 'category' => ProductCategory::Coastal, 'wea_warning' => false],
        'LSY' => ['name' => 'Lakeshore Flood Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::Coastal, 'wea_warning' => false],
        'CGR' => ['name' => 'Coastal Weather Observations', 'family' => ProductFamily::Informational, 'category' => ProductCategory::Coastal, 'wea_warning' => false],
        'MAW' => ['name' => 'Special Marine Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'MFY' => ['name' => 'Dense Fog Advisory (Marine)', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'MSY' => ['name' => 'Dense Smoke Advisory (Marine)', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'MAS' => ['name' => 'Marine Weather Statement', 'family' => ProductFamily::Statement, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'GLW' => ['name' => 'Gale Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'GLA' => ['name' => 'Gale Watch', 'family' => ProductFamily::Watch, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'SRW' => ['name' => 'Storm Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'SRA' => ['name' => 'Storm Watch', 'family' => ProductFamily::Watch, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'UPW' => ['name' => 'Heavy Freezing Spray Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'UPA' => ['name' => 'Heavy Freezing Spray Watch', 'family' => ProductFamily::Watch, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'UPY' => ['name' => 'Freezing Spray Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'SEW' => ['name' => 'Hazardous Seas Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'SEA' => ['name' => 'Hazardous Seas Watch', 'family' => ProductFamily::Watch, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'SUW' => ['name' => 'High Surf Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'SUY' => ['name' => 'High Surf Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'LOY' => ['name' => 'Low Water Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'HSF' => ['name' => 'High Seas Forecast', 'family' => ProductFamily::Informational, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'GLF' => ['name' => 'Great Lakes Forecast', 'family' => ProductFamily::Informational, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'OMR' => ['name' => 'Other Marine Reports', 'family' => ProductFamily::Informational, 'category' => ProductCategory::Marine, 'wea_warning' => false],
        'AFM' => ['name' => 'Area Forecast Matrices', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'PFM' => ['name' => 'Point Forecast Matrices', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'AWU' => ['name' => 'Area Weather Update', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'SFT' => ['name' => 'State Forecast Tabular Product', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'HWO' => ['name' => 'Hazardous Weather Outlook', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'NOW' => ['name' => 'Short-Term Forecast (NOWCast)', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'REC' => ['name' => 'Recreational Report', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'DY2' => ['name' => 'SPC Day 2 Convective Outlook', 'family' => ProductFamily::SpcWpc, 'category' => ProductCategory::ConvectiveOutlooks, 'wea_warning' => false],
        'DY3' => ['name' => 'SPC Day 3 Convective Outlook', 'family' => ProductFamily::SpcWpc, 'category' => ProductCategory::ConvectiveOutlooks, 'wea_warning' => false],
        'D48' => ['name' => 'SPC Day 4-8 Convective Outlook', 'family' => ProductFamily::SpcWpc, 'category' => ProductCategory::ConvectiveOutlooks, 'wea_warning' => false],
        'PWO' => ['name' => 'SPC Public Severe Weather Outlook (Statewide)', 'family' => ProductFamily::SpcWpc, 'category' => ProductCategory::OtherDiscussionsReportsAndOutlooks, 'wea_warning' => false],
        'STA' => ['name' => 'SPC Tornado and Severe Thunderstorm Reports', 'family' => ProductFamily::SpcWpc, 'category' => ProductCategory::OtherDiscussionsReportsAndOutlooks, 'wea_warning' => false],
        'LSR' => ['name' => 'Local Storm Report', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'RER' => ['name' => 'Record Event Report', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'LCO' => ['name' => 'Local Cooperative Observation', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'RTP' => ['name' => 'Regional Temperature and Precipitation', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'CLI' => ['name' => 'Daily Climate Report', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'DGT' => ['name' => 'Drought Information', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'HCM' => ['name' => 'Hydrometeorological Coordination Message', 'family' => ProductFamily::Informational, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'WRK' => ['name' => 'Work Product', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::OtherForecastsOutlooksDiscussionsAndReports, 'wea_warning' => false],
        'TOE' => ['name' => '911 Telephone Outage', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'ADA' => ['name' => 'Alarm/Alert Administrative Message (NCEP)', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'ADB' => ['name' => 'Alarm/Alert Administrative Message (Local WFO office)', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'ADM' => ['name' => 'Alert Administrative Message (NCEP)', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'ADN' => ['name' => 'Alert Administrative Message (Local WFO office)', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'ADR' => ['name' => 'NWS Administrative Message', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'AVA' => ['name' => 'Avalanche Watch', 'family' => ProductFamily::Watch, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'AVW' => ['name' => 'Avalanche Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'BLU' => ['name' => 'Blue Alert', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'CAE' => ['name' => 'Child Abduction Emergency', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => true],
        'CDW' => ['name' => 'Civil Danger Warning', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'CEM' => ['name' => 'Civil Emergency Message', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'EQW' => ['name' => 'Earthquake Warning', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'EVI' => ['name' => 'Evacuation Immediate', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'FTM' => ['name' => 'Free Text Message / Radar Outage Message', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'HMW' => ['name' => 'Hazardous Materials Warning', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => true],
        'LEW' => ['name' => 'Law Enforcement Warning', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'LAE' => ['name' => 'Local Area Emergency', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => true],
        'NUW' => ['name' => 'Nuclear Power Plant Warning', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => true],
        'PNS' => ['name' => 'Public Information Statement', 'family' => ProductFamily::Informational, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'RHW' => ['name' => 'Radiological Hazard Warning', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => true],
        'SPW' => ['name' => 'Shelter In Place Warning', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => true],
        'TSW' => ['name' => 'Tsunami Warning', 'family' => ProductFamily::Warning, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => true],
        'TSY' => ['name' => 'Tsunami Advisory', 'family' => ProductFamily::Advisory, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'TSA' => ['name' => 'Tsunami Watch', 'family' => ProductFamily::Watch, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => false],
        'VOW' => ['name' => 'Volcano Warning', 'family' => ProductFamily::Administrative, 'category' => ProductCategory::NonMeteorologicalHazardsAndAdministrativeMessages, 'wea_warning' => true],
        'AWW' => ['name' => 'Airport Weather Warning', 'family' => ProductFamily::Aviation, 'category' => ProductCategory::AirportRelated, 'wea_warning' => false],
        'SIG' => ['name' => 'International/Convective SIGMET', 'family' => ProductFamily::Aviation, 'category' => ProductCategory::AirportRelated, 'wea_warning' => false],
        'TAF' => ['name' => 'Terminal Aerodrome Forecast', 'family' => ProductFamily::Aviation, 'category' => ProductCategory::AirportRelated, 'wea_warning' => false],
        'OEP' => ['name' => 'TAF Collaboration Product', 'family' => ProductFamily::Aviation, 'category' => ProductCategory::AirportRelated, 'wea_warning' => false],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        Product::whereIn('code', self::ORPHAN_CODES)->delete();

        /** @var array<string, string> $descriptions */
        $descriptions = require database_path('data/product_catalog_descriptions.php');

        $nextSortOrder = (int) Product::max('sort_order') + 1;

        foreach (self::MATCHED as $abbr => $data) {
            Product::where('code', $data['code'])->update([
                'category' => $data['category'],
                'wea_warning' => $data['wea_warning'],
                'description' => trim($descriptions[$abbr]),
                'example' => $this->example($abbr),
            ]);
        }

        foreach (self::NEW as $abbr => $data) {
            // Warning/Watch/Advisory families always carry VTEC and UGC. Statement-family
            // products default to false/false since their VTEC-continuation status isn't
            // uniformly true (see SVS vs SPS in the base catalog's migration backfill) --
            // except FFS, the well-established VTEC-continuation statement for FF.W.
            $hasVtecAndUgc = $abbr === 'FFS' || in_array($data['family'], [
                ProductFamily::Warning,
                ProductFamily::Watch,
                ProductFamily::Advisory,
            ], true);

            Product::updateOrCreate(
                ['code' => $abbr],
                [
                    'name' => $data['name'],
                    'family' => $data['family'],
                    'category' => $data['category'],
                    'wea_warning' => $data['wea_warning'],
                    'has_vtec' => $hasVtecAndUgc,
                    'has_ugc' => $hasVtecAndUgc,
                    'description' => trim($descriptions[$abbr]),
                    'example' => $this->example($abbr),
                    'status' => ProductStatus::Active,
                    'sort_order' => $nextSortOrder++,
                ],
            );
        }
    }

    private function example(string $abbr): ?string
    {
        $path = database_path("product_examples/{$abbr}.txt");

        return is_file($path) ? file_get_contents($path) : null;
    }
}
