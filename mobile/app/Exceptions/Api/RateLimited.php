<?php

namespace App\Exceptions\Api;

/**
 * The server throttled the request (429). Still a Retryable, so a caller that does not tell it apart
 * treats it like any other "try again later"; the channel screens say that too many codes or tests
 * were sent.
 */
final class RateLimited extends Retryable {}
