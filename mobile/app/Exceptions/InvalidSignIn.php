<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * The browser sign-in came back in a state the app cannot trust: nothing was pending, or the
 * returned state does not match the one this phone started with.
 */
final class InvalidSignIn extends RuntimeException {}
