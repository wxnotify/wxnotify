<?php

namespace Tests\Fakes;

use Native\Mobile\Browser;

class RecordingBrowser extends Browser
{
    /** @var array<int, string> */
    public array $authUrls = [];

    /** @var array<int, string> */
    public array $inAppUrls = [];

    public bool $authSucceeds = true;

    public function auth(string $url): bool
    {
        $this->authUrls[] = $url;

        return $this->authSucceeds;
    }

    public function inApp(string $url): bool
    {
        $this->inAppUrls[] = $url;

        return true;
    }
}
