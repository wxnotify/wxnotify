<?php

namespace App\Support;

use App\Enums\MatchingMode;
use App\Models\Location;
use Illuminate\Support\Collection;

/**
 * How one alert rule matches one of its products: the mode, WFO, county/zone codes and locations that apply.
 * `inherited` is true when the product has no settings of its own and these are the rule's.
 */
final class ProductMatchSettings
{
    /**
     * @param  list<string>  $countyZoneCodes
     * @param  Collection<int, Location>  $locations  ordered by id
     */
    public function __construct(
        public readonly ?MatchingMode $mode,
        public readonly ?string $wfo,
        public readonly array $countyZoneCodes,
        public readonly Collection $locations,
        public readonly bool $inherited,
    ) {}
}
