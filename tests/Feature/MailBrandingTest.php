<?php

use App\Models\User;
use Filament\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

test('a markdown notification email shows the logo and the app name in its header', function () {
    config(['app.name' => 'WxNotify Test', 'app.url' => 'https://alerts.example.test']);

    $html = (string) (new MailMessage)->subject('Hello')->line('Body line')->render();

    expect($html)->toContain('src="https://alerts.example.test/images/logo.png"')
        ->and($html)->toContain('WxNotify Test')
        ->and($html)->toContain('Body line');
});

test('the logo url follows APP_URL and does not depend on the request host', function () {
    config(['app.url' => 'https://alerts.example.test/']);

    $html = (string) (new MailMessage)->line('x')->render();

    expect($html)->toContain('src="https://alerts.example.test/images/logo.png"');
});

test('filament email verification inherits the branded header', function () {
    config(['app.name' => 'WxNotify Test', 'app.url' => 'https://alerts.example.test']);
    $user = User::factory()->create();
    $notification = new VerifyEmail;
    $notification->url = 'https://alerts.example.test/verify/abc';

    $html = (string) $notification->toMail($user)->render();

    expect($html)->toContain('src="https://alerts.example.test/images/logo.png"')
        ->and($html)->toContain('WxNotify Test')
        ->and($html)->toContain('https://alerts.example.test/verify/abc');
});

test('the logo file the header points at exists', function () {
    expect(file_exists(public_path('images/logo.png')))->toBeTrue();
});
