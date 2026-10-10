<?php

namespace App\Exceptions\Api;

/**
 * The confirmation code was wrong or expired (422 invalid_code). `getMessage()` is the server's text.
 */
final class InvalidCode extends ApiException {}
