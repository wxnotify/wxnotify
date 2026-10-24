<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Channels\BeginChannelConnection;
use App\Actions\Channels\DeleteChannel;
use App\Actions\Channels\DispatchChannelConfirmationCode;
use App\Actions\Channels\RenameChannel;
use App\Actions\Channels\SendTestNotification;
use App\Actions\Channels\VerifyChannelConfirmationCode;
use App\Exceptions\ChannelCodeNotSent;
use App\Exceptions\ChannelManagedByApp;
use App\Http\Controllers\Controller;
use App\Http\Requests\RenameChannelRequest;
use App\Http\Requests\StoreChannelRequest;
use App\Http\Requests\VerifyChannelRequest;
use App\Http\Resources\Api\V1\ChannelResource;
use App\Http\Resources\Api\V1\ChannelTypeResource;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\ChannelTypes\ChannelTypeRegistry;
use App\Support\ChannelTypes\Contracts\ChannelType;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Spatie\Activitylog\Support\CauserResolver;

class ChannelController extends Controller
{
    public function types(Request $request, ChannelTypeRegistry $registry): AnonymousResourceCollection
    {
        $user = $request->user();

        return ChannelTypeResource::collection(
            $registry->creatableInApp($user)->map(fn (ChannelType $type): array => ['type' => $type, 'user' => $user]),
        );
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        return ChannelResource::collection($request->user()->notificationChannels()->orderBy('id')->get());
    }

    public function store(StoreChannelRequest $request, ChannelTypeRegistry $registry, BeginChannelConnection $begin): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $type = $registry->find($data['channel_type']);

        if (! $user->canAddChannel($type->slug())) {
            return response()->json([
                'error' => 'channel_limit',
                'message' => 'Your plan\'s free channel is in use. Remove a channel, or upgrade, to add another.',
            ], 422);
        }

        try {
            $channel = $this->asUser($user, fn () => $begin($user, $type, $data, $data['label'] ?? null));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                (string) (array_key_first(Arr::except($data, ['channel_type', 'label'])) ?? 'channel_type') => $e->getMessage(),
            ]);
        }

        return (new ChannelResource($channel))->response()->setStatusCode(201);
    }

    public function verify(VerifyChannelRequest $request, int $id, VerifyChannelConfirmationCode $verify): ChannelResource|JsonResponse
    {
        $channel = $this->find($request, $id);

        $verified = $this->asUser($request->user(), fn () => $verify($channel, trim($request->validated('code'))));

        if (! $verified) {
            return response()->json([
                'error' => 'invalid_code',
                'message' => 'That code didn\'t match, or it expired. Request a new code if you need one.',
            ], 422);
        }

        return new ChannelResource($channel->fresh());
    }

    public function resend(Request $request, int $id, DispatchChannelConfirmationCode $dispatch): Response|JsonResponse
    {
        $channel = $this->find($request, $id);

        if ($channel->isVerified()) {
            return response()->json(['error' => 'already_verified', 'message' => 'This channel is already verified.'], 409);
        }

        if (! $dispatch($channel)) {
            throw new ChannelCodeNotSent;
        }

        return response()->noContent();
    }

    public function test(Request $request, int $id, SendTestNotification $send): Response|JsonResponse
    {
        $channel = $this->find($request, $id);

        if (! $channel->isVerified()) {
            return $this->notVerifiedForTest();
        }

        if (! $send($channel)) {
            // The dispatcher refuses an unconfirmed channel; a Web Push subscription that expired during
            // this very send is unconfirmed now too, and says so instead of "check it is reachable".
            if (! $channel->isVerified()) {
                return $this->notVerifiedForTest();
            }

            return response()->json([
                'error' => 'test_failed',
                'message' => 'Couldn\'t send the test notification. Check the channel is still reachable and try again.',
            ], 502);
        }

        return response()->noContent();
    }

    public function update(RenameChannelRequest $request, int $id, RenameChannel $rename): ChannelResource
    {
        return new ChannelResource($rename($request->user(), $this->find($request, $id), $request->validated('label')));
    }

    public function destroy(Request $request, int $id, DeleteChannel $delete): Response
    {
        $delete($request->user(), $this->find($request, $id));

        return response()->noContent();
    }

    /**
     * The user's own channel, or a 404; a phone's push channel is refused (409) before any write.
     */
    private function notVerifiedForTest(): JsonResponse
    {
        return response()->json(['error' => 'not_verified', 'message' => 'Verify this channel before sending a test.'], 409);
    }

    private function find(Request $request, int $id): NotificationChannel
    {
        $channel = $request->user()->notificationChannels()->find($id) ?? abort(404);

        if ($channel->channel_type === 'mobile_push') {
            throw new ChannelManagedByApp;
        }

        return $channel;
    }

    /**
     * The API has no session, so the activity log's causer must be pinned (see `.ai/rules/actions.md`).
     */
    private function asUser(User $user, Closure $call): mixed
    {
        return app(CauserResolver::class)->withCauser($user, $call);
    }
}
