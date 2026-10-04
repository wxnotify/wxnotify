<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AppAuth\ExchangeAppAuthorizationCode;
use App\Http\Controllers\Controller;
use App\Http\Requests\ExchangeAppTokenRequest;
use App\Http\Resources\Api\V1\MeResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AppTokenController extends Controller
{
    /**
     * Trade the handoff's authorization code and PKCE verifier for an app token.
     */
    public function store(ExchangeAppTokenRequest $request, ExchangeAppAuthorizationCode $exchange): JsonResponse
    {
        $result = $exchange($request->validated('code'), $request->validated('code_verifier'));

        if ($result === null) {
            return self::invalidGrant();
        }

        return response()->json([
            'token' => $result['token'],
            'expires_at' => $result['expires_at']->toIso8601String(),
            'user' => (new MeResource($result['user']))->resolve(),
        ]);
    }

    /**
     * Sign out: revokes the token that made this request, and only that one.
     */
    public function destroy(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public static function invalidGrant(): JsonResponse
    {
        return response()->json([
            'error' => 'invalid_grant',
            'message' => 'The sign-in could not be completed. Start again from the app.',
        ], 422);
    }
}
