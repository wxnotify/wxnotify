<?php

namespace App\Exceptions\Api;

/**
 * The subscriber's plan has no room for another location: the server answered 422 location_limit.
 */
final class LocationLimitReached extends ApiException {}
