<?php

namespace App\Exceptions\Api;

/**
 * WxNotify is read-only for maintenance (a plain-text 503 on a write); the message is the server's.
 */
final class Maintenance extends ApiException {}
