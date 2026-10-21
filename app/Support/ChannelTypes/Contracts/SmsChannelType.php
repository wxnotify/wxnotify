<?php

namespace App\Support\ChannelTypes\Contracts;

/**
 * A phone channel that texts through Apprise. The Apprise URL holds the credentials, so it
 * is built from `config` at send time and never stored.
 */
interface SmsChannelType extends PhoneChannelType
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string;
}
