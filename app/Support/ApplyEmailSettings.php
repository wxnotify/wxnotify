<?php

namespace App\Support;

use App\Settings\IntegrationSettings;
use Throwable;

/**
 * Applies the admin-editable email settings on top of the .env mail config.
 * Runs at boot, so it must never throw: when the settings cannot be read
 * (missing table or rows, a fresh install, during migrate) the .env
 * configuration simply stays in force. The fail-safe direction is "use the
 * .env configuration", so if the settings cannot be read a deliberately
 * disabled relay will send via the .env mailer rather than being suppressed.
 * It is skipped while caching config, so settings-derived values are never
 * baked into the cached config file.
 */
class ApplyEmailSettings
{
    /**
     * Mailers that are never offered as the default mailer.
     *
     * @var list<string>
     */
    public const EXCLUDED_MAILERS = ['array', 'log', 'failover', 'roundrobin'];

    public function __invoke(): void
    {
        if (app()->runningConsoleCommand('config:cache', 'optimize')) {
            return;
        }

        try {
            $settings = app(IntegrationSettings::class);

            $relayEnabled = $settings->email_relay_enabled;
            $mailer = $settings->email_mailer;
            $fromAddress = $settings->email_from_address;
            $fromName = $settings->email_from_name;
        } catch (Throwable) {
            return;
        }

        if (! $relayEnabled) {
            config(['mail.default' => 'log']);
        } elseif (filled($mailer) && ! in_array($mailer, self::EXCLUDED_MAILERS, true) && is_array(config("mail.mailers.{$mailer}"))) {
            config(['mail.default' => $mailer]);
        }

        if (filled($fromAddress)) {
            config(['mail.from.address' => $fromAddress]);
        }

        if (filled($fromName)) {
            config(['mail.from.name' => $fromName]);
        }
    }
}
