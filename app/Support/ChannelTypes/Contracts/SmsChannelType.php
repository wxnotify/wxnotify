<?php

namespace App\Support\ChannelTypes\Contracts;

/**
 * A phone channel that texts through Apprise. The Apprise URL holds the credentials, so it
 * is built from `config` at send time and never stored.
 */
interface SmsChannelType extends PhoneChannelType
{
    /**
     * The `config` keys a send cannot do without; the transport refuses a channel where any is
     * missing or blank. Credential keys, `from` and `to`; never the consent record.
     *
     * @return list<string>
     */
    public function requiredConfigKeys(): array;

    /**
     * @param  array<string, mixed>  $config
     */
    public function appriseUrl(array $config): string;
}
