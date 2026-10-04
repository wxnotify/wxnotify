<?php

namespace App\Services;

use App\Exceptions\InvalidSignIn;
use App\Support\LocalCache;
use App\Support\Pkce;
use App\Support\TokenStore;
use Illuminate\Support\Str;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Device;

/**
 * The app half of the browser sign-in: start it (PKCE pair, state, system browser), finish
 * it when the deep link comes back (check the state, trade the code for a token), or drop it
 * when the subscriber cancels. The pending sign-in is single-use: any outcome spends it.
 */
class SignIn
{
    public function __construct(
        private TokenStore $tokens,
        private WxNotifyApi $api,
        private LocalCache $cache,
    ) {}

    public function begin(): bool
    {
        ['verifier' => $verifier, 'challenge' => $challenge] = Pkce::pair();
        $state = Pkce::state();

        $this->tokens->begin($verifier, $state);

        return Browser::auth(config('wxnotify.api_url').'/app-auth/authorize?'.http_build_query([
            'challenge' => $challenge,
            'state' => $state,
            'device_name' => $this->deviceName(),
            'redirect' => config('wxnotify.callback_uri'),
        ]));
    }

    /**
     * @return array<string, mixed> the signed-in subscriber, as /api/v1/me describes them
     *
     * @throws InvalidSignIn when there is no sign-in in progress or the state does not match
     */
    public function complete(string $code, string $state): array
    {
        $pending = $this->tokens->pending();
        $this->tokens->clearPending();

        if ($pending === null || ! hash_equals($pending['state'], $state)) {
            throw new InvalidSignIn;
        }

        $result = $this->api->exchangeCode($code, $pending['verifier']);

        $this->tokens->put($result['token']);
        $this->cache->rememberProfile($result['user']);

        return $result['user'];
    }

    public function denied(): void
    {
        $this->tokens->clearPending();
    }

    private function deviceName(): string
    {
        $info = json_decode((string) Device::getInfo(), true);
        $name = is_array($info) ? ($info['name'] ?? $info['model'] ?? null) : null;
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $name);

        return filled($name) ? Str::limit($name, 80, '') : 'WxNotify app';
    }
}
