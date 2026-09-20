<?php

namespace App\Support\Delivery\Transports;

use App\Models\NotificationChannel;
use App\Support\ChannelTypes\WebhookChannelType;
use App\Support\Delivery\DeliveryResult;
use App\Support\Delivery\Pauser;
use App\Support\Delivery\WebhookHeaderGuard;
use App\Support\Delivery\WebhookUrlGuard;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\ConnectException;
use Spatie\WebhookServer\WebhookCall;
use Throwable;

/**
 * spatie/laravel-webhook-server 3.11 spike: dispatchSync() + throwExceptionOnFailure()
 * rethrows Guzzle's ServerException/ConnectException synchronously (no failed_jobs row);
 * CallWebhookJob::getClient() resolves app(GuzzleHttp\Client::class) — the test seam;
 * DefaultSigner sets header "Signature" = hash_hmac('sha256', json_encode($payload), $secret),
 * and the body is exactly that same json_encode($payload), so the raw body verifies.
 */
class WebhookTransport implements Transport
{
    /** @var list<string> */
    private const RESERVED_KEYS = ['type', 'title', 'body', 'sent_at'];

    /**
     * The top-level keys an alert payload carries, in order. The channel form's
     * "what we send" description is built from this list, and a test asserts a
     * real payload's keys still match it — so the documentation cannot drift
     * away from payload() below.
     *
     * @var list<string>
     */
    public const ALERT_PAYLOAD_KEYS = ['type', 'title', 'body', 'sent_at', 'alert'];

    /** The key a confirmation payload carries in place of `alert`. */
    public const CONFIRMATION_ONLY_KEY = 'code';

    /**
     * The top-level keys a confirmation payload carries, in order.
     *
     * @var list<string>
     */
    public const CONFIRMATION_PAYLOAD_KEYS = ['type', 'title', 'body', 'sent_at', self::CONFIRMATION_ONLY_KEY];

    /** Seconds to wait after the first failed attempt, then after each later one. */
    private const FIRST_RETRY_PAUSE = 1;

    private const LATER_RETRY_PAUSE = 3;

    /** HTTP statuses worth another attempt; everything else 4xx/3xx is final. */
    private const RETRYABLE_STATUSES = [408, 429];

    public function __construct(
        private readonly WebhookUrlGuard $guard,
        private readonly WebhookHeaderGuard $headerGuard,
        private readonly Pauser $pauser,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function send(NotificationChannel $channel, string $body, ?string $title, array $meta = []): DeliveryResult
    {
        // withDefaults() also clamps tries/timeout and normalises the verb, so a
        // config written before an option existed — or edited outside the form —
        // can never push an out-of-range value at Guzzle. Clamping rather than
        // failing keeps a legacy or slightly-off channel delivering.
        $config = WebhookChannelType::withDefaults($channel->config);

        $url = $config['url'];
        $secret = $config['secret'];

        if (! is_string($url) || $url === '') {
            return DeliveryResult::failed('This webhook channel is missing its URL or signing secret.');
        }

        if ($config['sign'] && (! is_string($secret) || $secret === '')) {
            return DeliveryResult::failed('This webhook channel is missing its URL or signing secret.');
        }

        // Re-checked on every send: DNS can change after the URL was saved.
        if (($violation = $this->guard->violation($url)) !== null) {
            return DeliveryResult::failed($violation);
        }

        $proxy = null;

        if ($config['use_proxy']) {
            $proxy = $config['proxy_url'];

            if (! is_string($proxy) || $proxy === '') {
                return DeliveryResult::failed('This webhook channel is set to use a proxy but has no proxy URL.');
            }

            // Same reasoning as the target URL: the proxy host is subscriber-controlled.
            if (($violation = $this->guard->proxyViolation($proxy)) !== null) {
                return DeliveryResult::failed($violation);
            }
        }

        // Defence in depth: the form already refuses these. A stored header that
        // no longer validates fails the delivery outright rather than being
        // silently dropped — and the message is a fixed string, because header
        // values are credentials.
        if ($this->headerGuard->violation($config['headers']) !== null) {
            return DeliveryResult::failed('Webhook delivery failed: this channel\'s extra headers are no longer valid.');
        }

        return $this->attempt($config, $proxy, $this->payload($body, $title, $meta));
    }

    /**
     * Up to `tries` attempts in a row, inside this one queued job.
     *
     * Spatie is given maximumTries(1) on every call on purpose: with
     * dispatchSync(), a multi-try CallWebhookJob releases itself back onto the
     * queue after a failure and returns without throwing, so the caller would
     * record a successful delivery that never happened. Retrying here keeps the
     * DeliveryResult honest.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $payload
     */
    private function attempt(array $config, ?string $proxy, array $payload): DeliveryResult
    {
        $error = 'Webhook delivery failed: the endpoint did not accept the message.';

        for ($attempt = 1; $attempt <= $config['tries']; $attempt++) {
            try {
                $call = WebhookCall::create()
                    ->url($config['url'])
                    ->payload($payload)
                    ->useHttpVerb($config['method'])
                    ->verifySsl($config['verify_ssl'])
                    ->useTimestamp($config['use_timestamp'])
                    ->timeoutInSeconds($config['timeout'])
                    ->withHeaders(array_map(static fn (mixed $value): string => (string) $value, $config['headers']))
                    ->maximumTries(1)
                    ->throwExceptionOnFailure();

                $config['sign'] ? $call->useSecret($config['secret']) : $call->doNotSign();

                if ($proxy !== null) {
                    $call->useProxy($proxy);
                }

                $call->dispatchSync();

                return DeliveryResult::delivered();
            } catch (Throwable $e) {
                $error = $this->failureMessage($e);

                if ($attempt >= $config['tries'] || ! $this->isRetryable($e)) {
                    break;
                }

                $this->pauser->pause($attempt === 1 ? self::FIRST_RETRY_PAUSE : self::LATER_RETRY_PAUSE);
            }
        }

        return DeliveryResult::failed($error);
    }

    /**
     * Worth another attempt: the endpoint was unreachable, or answered with a
     * status that says "later" (5xx, 408, 429). An ordinary 4xx is the
     * endpoint's settled answer, and a 3xx is a redirect this app refuses to
     * follow — repeating either only wastes the subscriber's endpoint.
     */
    private function isRetryable(Throwable $e): bool
    {
        if ($e instanceof ConnectException) {
            return true;
        }

        if ($e instanceof BadResponseException) {
            $status = $e->getResponse()->getStatusCode();

            return $status >= 500 || in_array($status, self::RETRYABLE_STATUSES, true);
        }

        return false;
    }

    /**
     * The documented webhook body shape.
     *
     * `type`, `title`, `body` and `sent_at` are reserved: they are always the
     * transport's own values, never a caller's meta key of the same name. An
     * alert's remaining meta (rule, product, …) is nested under `alert`; a
     * confirmation keeps its `code` at the top level.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function payload(string $body, ?string $title, array $meta): array
    {
        $type = is_string($meta['type'] ?? null) ? $meta['type'] : 'alert';
        $details = array_diff_key($meta, array_flip(self::RESERVED_KEYS));

        $payload = [
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'sent_at' => now()->utc()->toIso8601String(),
        ];

        // Always present for an alert, so consumers can read payload.alert.*
        // without first testing whether the key exists at all.
        return $type === 'alert'
            ? $payload + ['alert' => $details]
            : $payload + $details;
    }

    /**
     * Built from the exception's type and HTTP status, never from its message.
     *
     * Guzzle renders the full request URI (which routinely carries the subscriber's
     * only credential in its query string) and ~120 characters of the endpoint's
     * response body into every message, and this string ends up in
     * delivery_logs.error_detail, which staff can read. Redacting known substrings
     * would only catch the spellings we thought of; composing the message ourselves
     * cannot leak anything at all — including the proxy URL and header values,
     * which Guzzle also renders into connection errors.
     */
    private function failureMessage(Throwable $e): string
    {
        if ($e instanceof BadResponseException) {
            return 'Webhook delivery failed: the endpoint returned HTTP '.$e->getResponse()->getStatusCode().'.';
        }

        if ($e instanceof ConnectException) {
            return 'Webhook delivery failed: the endpoint could not be reached.';
        }

        // Spatie raises a plain exception for any other non-2xx it sees itself —
        // notably a 3xx, which this app refuses to follow.
        return 'Webhook delivery failed: the endpoint did not accept the message.';
    }
}
