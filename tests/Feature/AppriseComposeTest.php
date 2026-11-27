<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Every Compose file runs the same pinned Apprise server. It has no login, and WxNotify only
 * uses its stateless /notify/ endpoint, so reminders (scheduled sends) and saved
 * configurations stay off.
 */
it('pins the Apprise server and turns off the features WxNotify does not use', function (string $file) {
    $apprise = Yaml::parseFile(base_path($file))['services']['apprise'];

    expect($apprise['image'])->toBe('caronc/apprise:2.2.0')
        ->and($apprise['environment'] ?? null)->toBe([
            'APPRISE_REMINDER_ENABLED' => 'no',
            'APPRISE_STATEFUL_MODE' => 'disabled',
        ]);
})->with([
    'development' => 'compose.yaml',
    'one server' => 'deploy/single-server/compose.yaml',
    'four servers' => 'deploy/multi-server/compose.yaml',
]);
