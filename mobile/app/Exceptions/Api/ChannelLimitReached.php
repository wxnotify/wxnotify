<?php

namespace App\Exceptions\Api;

/**
 * The subscriber's plan has no room for another channel: the server answered 422 channel_limit.
 * `getMessage()` is the server's text.
 */
final class ChannelLimitReached extends ApiException {}
