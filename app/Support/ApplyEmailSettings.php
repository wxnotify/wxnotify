<?php

namespace App\Support;

use App\Settings\IntegrationSettings;
use Throwable;

/**
 * Applies the admin-editable email settings on top of the .env mail config.
 * Runs at boot, so it must never throw: when the settings cannot be read
 * (missing table or rows, a fresh install, during migrate) the .env
 * configuration simply stays in force.
 */
class ApplyEmailSettings
{
    public function __invoke(): void
    {
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
        } elseif (filled($mailer) && is_array(config("mail.mailers.{$mailer}"))) {
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
