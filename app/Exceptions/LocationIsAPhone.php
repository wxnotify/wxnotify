<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The location is kept current by a phone; sharing is turned off first, never by deleting the location.
 */
class LocationIsAPhone extends RuntimeException {}
