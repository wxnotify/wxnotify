<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Channels\RegisterMobilePushDevice;
use App\Actions\Channels\UnregisterMobilePushDevice;
use App\Http\Controllers\Controller;
use App\Http\Requests\RegisterPushTokenRequest;
use App\Models\NotificationChannel;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

class PushTokenController extends Controller
{
    /**
     * Register (or refresh) this phone's FCM token. Idempotent; never returns the token.
     */
    public function update(RegisterPushTokenRequest $request, RegisterMobilePushDevice $register): Response
    {
        $register(
            $request->user(),
            $this->accessToken($request),
            $request->validated('token'),
            $request->validated('platform'),
        );

        return response()->noContent();
    }

    /**
     * Turn push off for this phone.
     */
    public function destroy(Request $request, UnregisterMobilePushDevice $unregister): Response
    {
        $unregister($request->user(), $this->accessToken($request));

        return response()->noContent();
    }

    /**
     * Reset the unread push count (the app icon badge) of this phone only. Takes no id; always 204.
     */
    public function clearBadge(Request $request): Response
    {
        NotificationChannel::query()
            ->where('channel_type', 'mobile_push')
            ->where('personal_access_token_id', $this->accessToken($request)->id)
            ->get()
            ->filter(fn (NotificationChannel $channel): bool => ($channel->config['platform'] ?? null) === 'ios')
            ->each->resetUnreadPushCount();

        return response()->noContent();
    }

    private function accessToken(Request $request): PersonalAccessToken
    {
        $token = $request->user()->currentAccessToken();

        abort_unless($token instanceof PersonalAccessToken, 403);

        return $token;
    }
}
