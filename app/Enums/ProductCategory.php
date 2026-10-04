<?php

namespace App\Enums;

enum ProductCategory: string
{
    case TornadoAndSevereThunderstorm = 'tornado_and_severe_thunderstorm';
    case FloodingAndHydrological = 'flooding_and_hydrological';
    case WinterWeather = 'winter_weather';
    case NonPrecipitation = 'non_precipitation';
    case FireWeather = 'fire_weather';
    case HurricaneAndTropicalStorm = 'hurricane_and_tropical_storm';
    case Coastal = 'coastal';
    case Marine = 'marine';
    case OtherForecastsOutlooksDiscussionsAndReports = 'other_forecasts_outlooks_discussions_and_reports';
    case ConvectiveOutlooks = 'convective_outlooks';
    case OtherDiscussionsReportsAndOutlooks = 'other_discussions_reports_and_outlooks';
    case NonMeteorologicalHazardsAndAdministrativeMessages = 'non_meteorological_hazards_and_administrative_messages';
    case AirportRelated = 'airport_related';

    public function label(): string
    {
        return match ($this) {
            self::TornadoAndSevereThunderstorm => 'Tornado and Severe Thunderstorm',
            self::FloodingAndHydrological => 'Flooding and Hydrological',
            self::WinterWeather => 'Winter Weather',
            self::NonPrecipitation => 'Non-Precipitation',
            self::FireWeather => 'Fire Weather',
            self::HurricaneAndTropicalStorm => 'Hurricane and Tropical Storm',
            self::Coastal => 'Coastal',
            self::Marine => 'Marine',
            self::OtherForecastsOutlooksDiscussionsAndReports => 'Other Forecasts, Outlooks, Discussions, and Reports',
            self::ConvectiveOutlooks => 'Convective Outlooks',
            self::OtherDiscussionsReportsAndOutlooks => 'Other Discussions, Reports, and Outlooks',
            self::NonMeteorologicalHazardsAndAdministrativeMessages => 'Non-Meteorological Hazards and Administrative Messages',
            self::AirportRelated => 'Airport-related',
        };
    }

    /**
     * A blade-fontawesome (solid style) icon name.
     */
    public function icon(): string
    {
        return match ($this) {
            self::TornadoAndSevereThunderstorm => 'fas-tornado',
            self::FloodingAndHydrological => 'fas-house-flood-water',
            self::WinterWeather => 'fas-snowflake',
            self::NonPrecipitation => 'fas-wind',
            self::FireWeather => 'fas-fire',
            self::HurricaneAndTropicalStorm => 'fas-hurricane',
            self::Coastal => 'fas-umbrella-beach',
            self::Marine => 'fas-ship',
            self::NonMeteorologicalHazardsAndAdministrativeMessages => 'fas-house-tsunami',
            self::OtherForecastsOutlooksDiscussionsAndReports => 'fas-temperature-high',
            self::ConvectiveOutlooks => 'fas-cloud-bolt',
            self::OtherDiscussionsReportsAndOutlooks => 'fas-file-lines',
            self::AirportRelated => 'fas-plane',
        };
    }
}
