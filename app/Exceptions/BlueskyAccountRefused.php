<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * BlueskyAccountGuard would not verify the account. The message is always one of the guard's
 * fixed texts, never anything from a response. It is an InvalidArgumentException, so a refusal
 * at save time reaches the wizard (and BeginChannelConnection's callers) like any other
 * destination a type cannot build.
 */
class BlueskyAccountRefused extends InvalidArgumentException {}
