<?php

/**
 * @return array<string, mixed>
 */
function composerJson(): array
{
    return json_decode((string) file_get_contents(base_path('composer.json')), true, flags: JSON_THROW_ON_ERROR);
}

test('the global helper functions load in production, not only with dev dependencies', function () {
    $composer = composerJson();

    expect($composer['autoload']['files'] ?? [])->toContain('app/helpers.php')
        ->and($composer['autoload-dev']['files'] ?? [])->not->toContain('app/helpers.php');
});

test('the helper the dashboard panel calls while booting is defined', function () {
    expect(function_exists('generateBrandName'))->toBeTrue();
});
