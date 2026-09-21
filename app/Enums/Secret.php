<?php

namespace App\Enums;

enum Secret: string
{
    case StripeSecretKey = 'stripe_secret_key';
    case StripeWebhookSecret = 'stripe_webhook_secret';
    case PaddleApiKey = 'paddle_api_key';
    case PaddleWebhookSecret = 'paddle_webhook_secret';
    case FirebaseServiceAccount = 'firebase_service_account';
    case TraccarPollingToken = 'traccar_polling_token';

    public function label(): string
    {
        return match ($this) {
            self::StripeSecretKey => 'Stripe secret key',
            self::StripeWebhookSecret => 'Stripe webhook signing secret',
            self::PaddleApiKey => 'Paddle API key',
            self::PaddleWebhookSecret => 'Paddle webhook signing secret',
            self::FirebaseServiceAccount => 'Firebase service-account JSON',
            self::TraccarPollingToken => 'Traccar polling token',
        };
    }

    /**
     * The encrypted property on SecretSettings that holds this secret.
     */
    public function settingsProperty(): string
    {
        return $this->value;
    }

    /**
     * The config key (reading .env) used when nothing is stored.
     */
    public function configKey(): string
    {
        return match ($this) {
            self::StripeSecretKey => 'services.stripe.secret',
            self::StripeWebhookSecret => 'services.stripe.webhook_secret',
            self::PaddleApiKey => 'services.paddle.api_key',
            self::PaddleWebhookSecret => 'services.paddle.webhook_secret',
            self::FirebaseServiceAccount => 'services.firebase.service_account',
            self::TraccarPollingToken => 'services.traccar.polling_token',
        };
    }
}
