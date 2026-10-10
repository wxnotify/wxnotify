<?php

use App\NativeComponents\ChannelEditor;
use App\Support\LocalCache;
use App\Support\TokenStore;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

function editorChannelRow(array $overrides = []): array
{
    return [
        'id' => 5, 'label' => 'Team Discord', 'channel_type' => 'discord', 'type_label' => 'Discord', 'destination' => '••••1234',
        'destination_label' => 'Discord webhook', 'verified' => true, 'managed_by_app' => false, 'created_at' => '2026-10-01T12:00:00+00:00',
        ...$overrides,
    ];
}

function channelEditorFakes(array $listed, array $extra = []): void
{
    Http::fake([
        ...$extra,
        'wx.test/api/v1/channels' => Http::response(['data' => $listed]),
    ]);
}

function channelEditorTextsIn(array $node): array
{
    $texts = array_values(array_filter([$node['props']['text'] ?? null, $node['props']['label'] ?? null], 'is_string'));

    foreach ($node['children'] ?? [] as $child) {
        $texts = [...$texts, ...channelEditorTextsIn($child)];
    }

    return $texts;
}

/**
 * Matches the action area beside the buttons when it shows the text.
 */
function inChannelActions(string $text): Closure
{
    return fn (array $node): bool => ($node['ref'] ?? null) === 'channel-actions' && in_array($text, channelEditorTextsIn($node), true);
}

beforeEach(function () {
    (new TokenStore)->put('9|secret');
});

test('a verified channel shows its name, type and masked destination, Send test and Delete', function () {
    channelEditorFakes([editorChannelRow()]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->assertNavTitle('Channel')
        ->assertSet('draft.label', 'Team Discord')
        ->assertSee('Discord')
        ->assertSee('Discord webhook: ••••1234')
        ->assertSee('Send test')
        ->assertSee('Delete channel')
        ->assertDontSee('We sent a 6-digit code');
});

test('an unverified channel asks for the code it was sent, with Verify and Resend code, and no Send test', function () {
    channelEditorFakes([editorChannelRow(['verified' => false])]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->assertSee('We sent a 6-digit code to this channel.')
        ->assertSee('Verify')
        ->assertSee('Resend code')
        ->assertDontSee('Send test');
});

test('an empty code is refused locally, a wrong one shows the server\'s message, the right one verifies the channel in place', function () {
    channelEditorFakes([editorChannelRow(['verified' => false])], [
        'wx.test/api/v1/channels/5/verify' => fn (Request $r) => $r->data() === ['code' => '123456']
            ? Http::response(['data' => editorChannelRow()])
            : Http::response(['error' => 'invalid_code', 'message' => 'That code didn\'t match, or it expired. Request a new code if you need one.'], 422),
    ]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Verify')
        ->assertSee('Enter the 6-digit code.')
        ->input('field-code', '000000')
        ->tap('Verify')
        ->assertSee('That code didn\'t match, or it expired.')
        ->input('field-code', '123456')
        ->tap('Verify')
        ->assertSee('Channel verified.')
        ->assertSee('Send test')
        ->assertDontSee('We sent a 6-digit code')
        ->assertSet('code', '');
});

test('resend says a new code was sent', function () {
    channelEditorFakes([editorChannelRow(['verified' => false])], ['wx.test/api/v1/channels/5/resend' => Http::response(status: 204)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Resend code')
        ->assertElement('column', inChannelActions('We sent a new code.'));
});

test('resend says when a new code could not be sent', function () {
    channelEditorFakes([editorChannelRow(['verified' => false])], ['wx.test/api/v1/channels/5/resend' => Http::response(['error' => 'code_not_sent', 'message' => 'x'], 502)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Resend code')
        ->assertElement('column', inChannelActions('We couldn\'t send the confirmation code. Please try again in a moment.'));
});

test('resend clears a code that was typed and says a new one was sent', function () {
    channelEditorFakes([editorChannelRow(['verified' => false])], ['wx.test/api/v1/channels/5/resend' => Http::response(status: 204)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->input('field-code', '000000')
        ->tap('Resend code')
        ->assertSet('code', '')
        ->assertElement('column', inChannelActions('We sent a new code.'));
});

test('resend and Send test say when too many have been sent', function (bool $verified, string $button, string $endpoint) {
    channelEditorFakes([editorChannelRow(['verified' => $verified])], ["wx.test/api/v1/channels/5/{$endpoint}" => Http::response(['message' => 'Too Many Attempts.'], 429)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap($button)
        ->assertElement('column', inChannelActions('You\'ve sent too many codes or tests. Try again in a little while.'));
})->with([
    'resend' => [false, 'Resend code', 'resend'],
    'test' => [true, 'Send test', 'test'],
]);

test('a validation error on the code shows under the code field', function () {
    channelEditorFakes([editorChannelRow(['verified' => false])], [
        'wx.test/api/v1/channels/5/verify' => Http::response(['message' => 'The code field is required.', 'errors' => ['code' => ['The code field is required.']]], 422),
    ]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->input('field-code', '123456')
        ->tap('Verify')
        ->assertSet('codeError', 'The code field is required.')
        ->assertSee('The code field is required.');
});

test('Send test reports success beside the buttons', function () {
    channelEditorFakes([editorChannelRow()], ['wx.test/api/v1/channels/5/test' => Http::response(status: 204)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Send test')
        ->assertElement('column', inChannelActions('Test notification sent.'));
});

test('Send test reports failure beside the buttons', function () {
    channelEditorFakes([editorChannelRow()], ['wx.test/api/v1/channels/5/test' => Http::response(['error' => 'test_failed', 'message' => 'x'], 502)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Send test')
        ->assertElement('column', inChannelActions('Couldn\'t send the test notification. Check the channel is still reachable and try again.'));
});

test('renaming sends the new name, remembers it and goes back; a blank name is refused locally', function () {
    channelEditorFakes([editorChannelRow()], ['wx.test/api/v1/channels/5' => Http::response(['data' => editorChannelRow(['label' => 'Ops Discord'])])]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->input('field-label', '   ')
        ->tap('Save')
        ->assertSee('Give this channel a name.')
        ->assertNoNavigation()
        ->input('field-label', 'Ops Discord')
        ->tap('Save')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'PUT' && $r->data() === ['label' => 'Ops Discord']);
    expect(collect((new LocalCache)->channels())->firstWhere('id', 5)['label'])->toBe('Ops Discord');
});

test('leaving with an edited name asks to discard, leaving untouched does not', function () {
    channelEditorFakes([editorChannelRow()]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->call('onBackPressed')
        ->assertWentBack();

    Native::test(ChannelEditor::class, ['id' => 5])
        ->input('field-label', 'Changed')
        ->call('onBackPressed')
        ->assertNoNavigation()
        ->assertElement('column', inChannelActions('Discard your changes?'))
        ->tap('Discard changes')
        ->assertWentBack();
});

test('deleting asks first, then removes the channel from the server and the cache and goes back', function () {
    (new LocalCache)->rememberChannels([editorChannelRow(), editorChannelRow(['id' => 6])]);
    channelEditorFakes([editorChannelRow(), editorChannelRow(['id' => 6])], ['wx.test/api/v1/channels/5' => Http::response(status: 204)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Delete channel')
        ->assertElement('column', inChannelActions('Delete this channel? This can\'t be undone.'))
        ->tap('Yes, delete')
        ->assertWentBack();

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/channels/5'));
    expect(collect((new LocalCache)->channels())->pluck('id')->all())->toBe([6]);
});

test('a channel that is an alert\'s only one stays, with the server\'s message', function () {
    channelEditorFakes([editorChannelRow()], ['wx.test/api/v1/channels/5' => Http::response([
        'error' => 'channel_in_use', 'message' => 'Can\'t delete Team Discord. It\'s the only channel for: Storm warnings. Add another channel to those alerts first.', 'alerts' => ['Storm warnings'],
    ], 409)]);

    Native::test(ChannelEditor::class, ['id' => 5])
        ->tap('Delete channel')
        ->tap('Yes, delete')
        ->assertNoNavigation()
        ->assertElement('column', inChannelActions('Can\'t delete Team Discord. It\'s the only channel for: Storm warnings. Add another channel to those alerts first.'));
});

test('a channel already deleted elsewhere goes back as if deleted', function () {
    channelEditorFakes([editorChannelRow()], ['wx.test/api/v1/channels/5' => Http::response([], 404)]);

    Native::test(ChannelEditor::class, ['id' => 5])->tap('Delete channel')->tap('Yes, delete')->assertWentBack();
});

test('offline or in maintenance a write says so beside the buttons and keeps the edit', function () {
    channelEditorFakes([editorChannelRow()]);
    $screen = Native::test(ChannelEditor::class, ['id' => 5])->input('field-label', 'Changed');

    $this->network->connected = false;
    $screen->tap('Save')
        ->assertElement('column', inChannelActions('You\'re offline. Reconnect to save.'))
        ->assertSet('draft.label', 'Changed')
        ->assertNoNavigation();

    $this->network->connected = true;
    Http::fake(['wx.test/api/v1/channels/5' => Http::response('Back soon.', 503, ['Content-Type' => 'text/plain'])]);
    $screen->tap('Save')->assertElement('column', inChannelActions('Back soon.'))->assertSet('draft.label', 'Changed');
});

test('offline it opens from the remembered list, and with nothing remembered it says to connect', function () {
    (new LocalCache)->rememberChannels([editorChannelRow()]);
    $this->network->connected = false;

    Native::test(ChannelEditor::class, ['id' => 5])->assertSet('draft.label', 'Team Discord');

    (new LocalCache)->wipe();

    Native::test(ChannelEditor::class, ['id' => 5])->assertSee('Connect to the internet to open this channel.')->assertSee('Try again');
});

test('a channel that no longer exists is not opened', function () {
    channelEditorFakes([editorChannelRow(['id' => 7])]);

    Native::test(ChannelEditor::class, ['id' => 5])->assertSee('This channel no longer exists.')->assertDontSee('Delete channel');
});

test('a phone\'s own channel is not opened', function () {
    channelEditorFakes([editorChannelRow(['managed_by_app' => true, 'channel_type' => 'mobile_push', 'type_label' => 'Mobile Push'])]);

    Native::test(ChannelEditor::class, ['id' => 5])->assertSee('managed in Account')->assertDontSee('Delete channel');
});

test('a rejected token signs out', function () {
    Http::fake(['wx.test/api/v1/channels' => Http::response([], 401)]);

    Native::test(ChannelEditor::class, ['id' => 5])->assertReplacedWith('/sign-in');
});
