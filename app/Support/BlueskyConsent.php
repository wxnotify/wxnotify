<?php

namespace App\Support;

/**
 * What a subscriber agrees to when connecting a Bluesky account: every message is a public
 * post. Recorded like PhoneConsent: the VERSION and time in the channel's `config`, and an
 * activity-log entry holding only the type and the VERSION (never the handle or password).
 * Changing the wording means a new VERSION.
 */
class BlueskyConsent
{
    public const VERSION = '2026-10-public-posts';

    public const TEXT = 'Every alert, test and confirmation code is posted publicly on this Bluesky account and is visible to anyone.';
}
