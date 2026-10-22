<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\Delivery\DeliveryResult;
use App\Support\Delivery\VoiceScript;
use App\Support\PhoneNumber;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Places a phone call through the subscriber's own Twilio account, directly against Twilio's
 * REST API (not Apprise): one form-encoded POST of `To`, `From` and `Twiml` (VoiceScript) to
 * the fixed host, HTTP Basic `sid:token`, no redirects, 5 s to connect and 10 s in all.
 *
 * One attempt, ever: a call costs the subscriber money and rings a phone, so a 429, a 5xx or a
 * timeout is reported as a failure and never retried. A connection error or timeout is caught
 * here and never re-thrown, because a thrown exception would let a queued job retry and place a
 * second call. Twilio answering 201 means it accepted the call; whether anyone picked up needs
 * status callbacks (a later item).
 *
 * The Account SID goes into the URL path, so it must match `^AC[0-9a-f]{32}$` (any case) before
 * the URL is built; anything else fails before any HTTP. Failure text is fixed: the HTTP status
 * and an allow-list of Twilio error codes (read from the JSON body as an integer, nothing else
 * of the body is kept) map to fixed sentences. The SID, token, numbers and response body never
 * reach an error, and a caught exception is logged by class only.
 *
 * Checked against Twilio's docs (2026-10-22): the Calls resource takes
 * `application/x-www-form-urlencoded` `To`/`From`/`Twiml`, `Twiml` is limited to 4000
 * characters, a created resource answers 201, and an error body is `{code, message, more_info,
 * status}` with `code` optional. The error dictionary names 21217 "Phone number does not appear
 * to be valid", so it is read with 21211/21214 as a number Twilio cannot call; 21210 is "'From'
 * phone number not verified" and 21212 "Invalid 'From' Number" (both read as a From that is not
 * a voice number on the account); 21219 "'To' phone number not verified" is the trial-account
 * case.
 */
class TwilioVoiceTransport implements Transport
{
    public const ENDPOINT = 'https://api.twilio.com/2010-04-01/Accounts/%s/Calls.json';

    public const CONNECT_TIMEOUT = 5;

    public const TIMEOUT = 10;

    /**
     * `D`: `$` must not match before a trailing newline.
     */
    public const ACCOUNT_SID_PATTERN = '/^AC[0-9a-f]{32}$/iD';

    public const MISSING_SETTINGS = 'This voice channel is missing its settings.';

    public const INVALID_SID = "This voice channel's Twilio Account SID is not valid.";

    public const NO_ANSWER = 'Twilio did not answer in time; a call may still have been placed.';

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        $config = $channel->config;

        if (! is_array($config)) {
            return DeliveryResult::failed(self::MISSING_SETTINGS);
        }

        $sid = $config['account_sid'] ?? null;
        $token = $config['auth_token'] ?? null;
        $to = is_string($config['to'] ?? null) ? PhoneNumber::normalize($config['to']) : null;
        $from = is_string($config['from'] ?? null) ? PhoneNumber::normalize($config['from']) : null;

        if (! is_string($sid) || blank($sid) || ! is_string($token) || blank($token) || $to === null || $from === null) {
            return DeliveryResult::failed(self::MISSING_SETTINGS);
        }

        if (preg_match(self::ACCOUNT_SID_PATTERN, $sid) !== 1) {
            return DeliveryResult::failed(self::INVALID_SID);
        }

        try {
            $response = Http::asForm()
                ->withBasicAuth($sid, $token)
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TIMEOUT)
                ->withoutRedirecting()
                ->post(sprintf(self::ENDPOINT, $sid), [
                    'To' => $to,
                    'From' => $from,
                    'Twiml' => VoiceScript::compose($body, $title, $meta),
                ]);
        } catch (ConnectionException|TransferException $e) {
            Log::warning('Twilio voice call failed: '.$e::class);

            return DeliveryResult::failed(self::NO_ANSWER);
        }

        if ($response->status() === 201) {
            return DeliveryResult::delivered();
        }

        return DeliveryResult::failed(self::describe($response->status(), self::twilioCode($response)));
    }

    /**
     * Fixed text for a refused call: the HTTP status and Twilio's error code, nothing else.
     */
    public static function describe(int $status, ?int $twilioCode): string
    {
        return match (true) {
            $status === 401, $twilioCode === 20003 => 'Twilio refused the Account SID or auth token.',
            in_array($twilioCode, [21211, 21214, 21217], true) => 'Twilio cannot call this number.',
            in_array($twilioCode, [21210, 21212], true) => 'The From number is not a voice number on this Twilio account.',
            $twilioCode === 21219 => 'This Twilio trial account can only call numbers verified in Twilio.',
            $twilioCode === 21215 => "Twilio's geographic permissions on this account do not allow calling this number.",
            $status === 429 => 'Twilio is rate limiting this account; the call was not retried.',
            $status >= 500 => "Twilio had an error (HTTP {$status}); the call was not retried.",
            default => "Twilio refused the call (HTTP {$status}).",
        };
    }

    /**
     * Twilio's error code when the body is JSON with an integer `code`; never anything else.
     */
    private static function twilioCode(Response $response): ?int
    {
        $decoded = json_decode($response->body(), true);
        $code = is_array($decoded) ? ($decoded['code'] ?? null) : null;

        return is_int($code) ? $code : null;
    }
}
