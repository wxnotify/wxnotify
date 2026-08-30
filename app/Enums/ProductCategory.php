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
            self::NonMeteorologicalHazardsAndAdministrativeMessages => 'Non-Meteorological Hazards and Administrative Messages',
            self::AirportRelated => 'Airport-related',
        };
    }
}
