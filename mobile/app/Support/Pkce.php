<?php

namespace App\Support;

/**
 * PKCE (RFC 7636, S256) helpers for the browser sign-in. The verifier never leaves the app
 * until the code exchange, which is what makes an intercepted deep-link code useless.
 */
class Pkce
{
    /**
     * @return array{verifier: string, challenge: string}
     */
    public static function pair(): array
    {
        $verifier = self::base64Url(random_bytes(32));

        return ['verifier' => $verifier, 'challenge' => self::challengeFor($verifier)];
    }

    public static function challengeFor(string $verifier): string
    {
        return self::base64Url(hash('sha256', $verifier, true));
    }

    public static function state(): string
    {
        return self::base64Url(random_bytes(24));
    }

    private static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
