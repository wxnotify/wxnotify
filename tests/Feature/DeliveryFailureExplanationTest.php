<?php

use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ByoChannelType;
use App\Support\ChannelTypes\NtfyChannelType;
use App\Support\Delivery\ChannelDispatcher;
use App\Support\Delivery\ChatServerGuard;
use App\Support\Delivery\Transports\TwilioVoiceTransport;
use App\Support\DeliveryFailureExplanation;

beforeEach(function () {
    DeliveryFailureExplanation::flush();
});

/**
 * @return array{reason: string, fix: ?string, ours: bool, channels: bool}
 */
function explanationOf(string $detail): array
{
    return DeliveryFailureExplanation::for('failure', $detail);
}

function explanationIsGeneric(array $explanation): bool
{
    return $explanation['reason'] === DeliveryFailureExplanation::GENERIC_REASON;
}

test('a delivery that went out has nothing to explain', function () {
    expect(DeliveryFailureExplanation::for('success', null))->toBeNull()
        ->and(DeliveryFailureExplanation::for('anything-else', 'text'))->toBeNull();
});

test('quiet hours and a turned-off rule are explained by status, whatever the stored text', function () {
    $quiet = DeliveryFailureExplanation::for('suppressed', null);
    $skipped = DeliveryFailureExplanation::for('skipped', 'The rule was deactivated after this delivery was dispatched.');

    expect($quiet['reason'])->toBe('Held back during your quiet hours.')
        ->and($quiet['fix'])->toContain('quiet hours')
        ->and($skipped['reason'])->toBe('The alert was turned off before this delivery went out.')
        ->and($skipped['fix'])->toContain('My Alerts')
        ->and($skipped['ours'])->toBeFalse();
});

test('each kind of failure gets its reason, a fix, and whether it is ours', function (string $detail, string $reasonStartsWith, bool $ours, bool $channelsLink) {
    $explanation = explanationOf($detail);

    expect($explanation['reason'])->toStartWith($reasonStartsWith)
        ->and($explanation['ours'])->toBe($ours)
        ->and($explanation['channels'])->toBe($channelsLink)
        ->and($explanation['fix'])->not->toBeEmpty();
})->with([
    'webhook 404' => ['Webhook delivery failed: the endpoint returned HTTP 404.', 'Your endpoint refused the message.', false, true],
    'webhook 500' => ['Webhook delivery failed: the endpoint returned HTTP 503.', 'Your endpoint had a problem', false, true],
    'webhook unreachable' => ['Webhook delivery failed: the endpoint could not be reached.', "We couldn't reach the destination.", false, true],
    'webhook redirect' => ['Webhook delivery failed: the endpoint did not accept the message.', "Your endpoint didn't accept the message", false, true],
    'webhook no secret' => ['This webhook channel is missing its URL or signing secret.', 'This channel is missing part of its setup.', false, true],
    'webhook headers' => ["Webhook delivery failed: this channel's extra headers are no longer valid.", 'This channel is missing part of its setup.', false, true],
    'no push token' => ['This device channel has no push token; open the app to register it again.', "This phone isn't registered", false, false],
    'web push expired' => ['This browser subscription is no longer valid; re-enable Web Push from Notification Channels.', "This browser's push subscription has expired.", false, true],
    'web push rejected' => ['Push service rejected the message (HTTP 410).', "The browser's push service rejected", false, true],
    'plan' => ['This channel needs the Plus plan or higher.', 'Your plan no longer includes', false, true],
    'unconfirmed' => ['This number has not been confirmed yet.', "This channel hasn't been confirmed yet.", false, true],
    'private network' => ['That server is on a private or reserved network, which is not allowed.', "That address isn't allowed.", false, true],
    'apprise' => ['Apprise reported non-delivery.', "The service didn't accept the message.", false, true],
    'twilio' => ['Twilio refused the Account SID or auth token.', 'Twilio refused the Account SID or auth token.', false, true],
    'mail off' => ['Email delivery is turned off on this server.', DeliveryFailureExplanation::OURS_REASON, true, false],
    'web push not configured' => ['Web Push is not configured on this server (missing VAPID keys).', DeliveryFailureExplanation::OURS_REASON, true, false],
]);

test('an unrecognised failure gets the generic text and none of the stored words', function () {
    $explanation = explanationOf('Provider said: token sk_live_SECRET rejected for https://hooks.example.com/abc');

    expect($explanation['reason'])->toBe(DeliveryFailureExplanation::GENERIC_REASON)
        ->and($explanation['fix'])->toBe(DeliveryFailureExplanation::GENERIC_FIX)
        ->and(json_encode($explanation))->not->toContain('SECRET')->not->toContain('hooks.example.com');
});

test('a missing stored text is the generic failure', function () {
    expect(DeliveryFailureExplanation::for('failure', null)['reason'])->toBe(DeliveryFailureExplanation::GENERIC_REASON);
});

test('every BYO channel type\'s own failure message is split into its reason and its fix', function () {
    $byo = app(ChannelTypeRegistry::class)->all()->filter(fn ($type) => $type instanceof ByoChannelType);

    expect($byo)->not->toBeEmpty();

    foreach ($byo as $type) {
        $explanation = explanationOf($type->failureMessage());

        expect(explanationIsGeneric($explanation))->toBeFalse($type->slug())
            ->and($explanation['reason'])->toEndWith('.')
            ->and($explanation['fix'])->toStartWith('Check');
    }
});

test('every fixed failure sentence in the delivery code is explained', function () {
    $texts = [
        ChannelDispatcher::TIER_REFUSAL,
        ChannelDispatcher::UNCONFIRMED_REFUSAL,
        ChannelDispatcher::UNCONFIRMED_NUMBER_REFUSAL,
        TwilioVoiceTransport::MISSING_SETTINGS,
        TwilioVoiceTransport::INVALID_SID,
        TwilioVoiceTransport::INVALID_NUMBERS,
        TwilioVoiceTransport::NOT_VOICE,
        TwilioVoiceTransport::NO_ANSWER,
        TwilioVoiceTransport::describe(401, null),
        TwilioVoiceTransport::describe(400, 21211),
        TwilioVoiceTransport::describe(400, 21210),
        TwilioVoiceTransport::describe(400, 21219),
        TwilioVoiceTransport::describe(400, 21215),
        TwilioVoiceTransport::describe(429, null),
        TwilioVoiceTransport::describe(503, null),
        TwilioVoiceTransport::describe(400, null),
        NtfyChannelType::SERVER_REFUSED,
        NtfyChannelType::SERVER_UNRESOLVED,
        ChatServerGuard::HTTPS_ONLY,
        ChatServerGuard::NO_USERINFO,
        ChatServerGuard::NO_QUERY,
        ChatServerGuard::BAD_PORT,
        ChatServerGuard::NOT_FOUND,
        ChatServerGuard::PRIVATE_NETWORK,
        'That host name points at a private network address.',
        'That host name could not be resolved.',
        'That address resolves to a private or reserved network, which is not allowed.',
        'The proxy host name could not be resolved.',
        'Webhook delivery failed: the endpoint returned HTTP 429.',
        'The push service could not be reached.',
    ];

    foreach ($texts as $text) {
        expect(explanationIsGeneric(explanationOf($text)))->toBeFalse($text);
    }
});

test('every literal failure message written in the app code is explained, so a new one cannot be added without an entry', function () {
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        preg_match_all("/DeliveryResult::failed\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file->getPathname()), $matches);

        foreach ($matches[1] as $literal) {
            $found[] = stripslashes($literal);
        }
    }

    expect(count($found))->toBeGreaterThan(15);

    foreach ($found as $literal) {
        expect(explanationIsGeneric(explanationOf($literal)))->toBeFalse($literal);
    }
});
