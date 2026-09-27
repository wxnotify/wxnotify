<?php

use App\Mail\ChannelMessageMail;

test('the subject is the title, falling back to the app name', function () {
    config(['app.name' => 'WxNotify Test']);

    expect((new ChannelMessageMail('Tornado Warning', 'body'))->envelope()->subject)->toBe('Tornado Warning')
        ->and((new ChannelMessageMail(null, 'body'))->envelope()->subject)->toBe('WxNotify Test')
        ->and((new ChannelMessageMail('', 'body'))->envelope()->subject)->toBe('WxNotify Test');
});

test('the body is rendered inside an escaped pre-formatted block under the branded header', function () {
    config(['app.name' => 'WxNotify Test', 'app.url' => 'https://alerts.example.test']);

    $html = (new ChannelMessageMail('Title', "TORNADO WARNING\n  indented line <script>alert(1)</script> & more"))->render();

    expect($html)->toContain('<pre')
        ->and($html)->toContain('white-space: pre-wrap')
        ->and($html)->toContain('TORNADO WARNING')
        ->and($html)->toContain('  indented line')
        ->and($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; &amp; more')
        ->and($html)->not->toContain('<script>alert(1)</script>')
        ->and($html)->toContain('src="https://alerts.example.test/images/logo.png"');
});

test('blank lines and leading spaces in the body survive markdown processing', function () {
    $html = (new ChannelMessageMail('Title', "LINE ONE\n\n    four-space indented line\n\nLINE FOUR"))->render();

    expect($html)->toContain("LINE ONE\n\n    four-space indented line\n\nLINE FOUR");
});

test('the plain text alternative carries the app name and the body without html', function () {
    config(['app.name' => 'WxNotify Test']);
    $mailable = new ChannelMessageMail('Title', "TORNADO WARNING\n<b>literal</b> & more\n\n\n\nEND");

    $mailable->assertSeeInText('WxNotify Test')
        ->assertSeeInText("TORNADO WARNING\n<b>literal</b> & more\n\n\n\nEND")
        ->assertDontSeeInText('<pre');
});
