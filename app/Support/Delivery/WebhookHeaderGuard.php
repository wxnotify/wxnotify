<?php

namespace App\Support\Delivery;

/**
 * Validates the subscriber-supplied extra headers of a webhook channel.
 *
 * Header VALUES are secrets (they routinely carry an API key or bearer token),
 * so no message this class returns ever repeats one back — only the header
 * name, which the subscriber typed and which is already visible in their own
 * form.
 */
class WebhookHeaderGuard
{
    public const MAX_HEADERS = 20;

    public const MAX_VALUE_LENGTH = 1024;

    /**
     * Headers the transport, Guzzle or the signer sets itself. Letting a
     * subscriber override any of them would either break the request framing
     * (Content-Length, Transfer-Encoding), retarget it (Host), or forge the
     * very header a receiver authenticates with.
     *
     * @var list<string>
     */
    private const RESERVED_NAMES = [
        'host',
        'content-length',
        'content-type',
        'transfer-encoding',
        'connection',
        'upgrade',
        'te',
        'trailer',
    ];

    /** RFC 7230 token: the only characters a header field-name may contain. */
    private const TOKEN_PATTERN = '/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/';

    /**
     * A human-readable reason the headers must not be sent, or null when they
     * are safe.
     */
    public function violation(mixed $headers): ?string
    {
        if ($headers === null || $headers === []) {
            return null;
        }

        if (! is_array($headers)) {
            return 'Extra headers must be a list of name and value pairs.';
        }

        if (count($headers) > self::MAX_HEADERS) {
            return 'Use at most '.self::MAX_HEADERS.' extra headers.';
        }

        $reserved = $this->reservedNames();

        foreach ($headers as $name => $value) {
            $name = (string) $name;

            if (preg_match(self::TOKEN_PATTERN, $name) !== 1) {
                return 'Header names may only contain letters, digits and the characters !#$%&\'*+-.^_`|~.';
            }

            if (in_array(strtolower($name), $reserved, true)) {
                return 'The header "'.$name.'" is set by WxNotify and cannot be overridden.';
            }

            if (is_array($value) || is_object($value)) {
                return 'The value of header "'.$name.'" must be text.';
            }

            $value = (string) ($value ?? '');

            if (strlen($value) > self::MAX_VALUE_LENGTH) {
                return 'The value of header "'.$name.'" is longer than '.self::MAX_VALUE_LENGTH.' characters.';
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                return 'The value of header "'.$name.'" contains a control character (such as a line break), which is not allowed.';
            }
        }

        return null;
    }

    /**
     * The fixed list plus whichever header names the signer and the timestamp
     * option are currently configured to use — renaming those in
     * config/webhook-server.php must move the reservation with them.
     *
     * @return list<string>
     */
    private function reservedNames(): array
    {
        return [
            ...self::RESERVED_NAMES,
            strtolower((string) config('webhook-server.signature_header_name')),
            strtolower((string) config('webhook-server.timestamp_header_name')),
        ];
    }
}
