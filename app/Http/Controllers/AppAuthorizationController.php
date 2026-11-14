<?php

namespace App\Http\Controllers;

use App\Actions\AppAuth\IssueAppAuthorizationCode;
use App\Http\Requests\AuthorizeAppRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use STS\FilamentImpersonate\ImpersonateManager;

/**
 * The browser half of the mobile sign-in handoff: after the subscriber has signed in the
 * normal way, ask them to approve the app, then send a single-use code back to it.
 */
class AppAuthorizationController extends Controller
{
    public function show(AuthorizeAppRequest $request): RedirectResponse|Response
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        return self::unframed(response()->view('app-auth.page', [
            'title' => 'Sign in to the WxNotify app',
            'form' => [
                'device' => $request->validated('device_name'),
                'email' => $request->user()->email,
                'fields' => $request->safe()->only(['challenge', 'state', 'device_name', 'redirect']),
            ],
        ]));
    }

    public function store(AuthorizeAppRequest $request, IssueAppAuthorizationCode $issue): RedirectResponse|Response
    {
        if ($refusal = $this->refusal($request)) {
            return $refusal;
        }

        $state = $request->validated('state');

        if ($request->validated('decision') === 'deny') {
            return redirect()->away(config('wxnotify.app_auth.denied_uri').'/'.$state);
        }

        $code = $issue($request->user(), $request->validated('challenge'), $request->validated('device_name'));

        return redirect()->away(config('wxnotify.app_auth.callback_uri').'/'.$code.'/'.$state);
    }

    private function refusal(AuthorizeAppRequest $request): RedirectResponse|Response|null
    {
        if (app(ImpersonateManager::class)->isImpersonating()) {
            return self::unframed(response()->view('app-auth.page', [
                'title' => 'Sign-in is unavailable',
                'message' => 'Not available while impersonating a subscriber.',
            ], 403));
        }

        if ($request->user()->isPendingDeletion()) {
            return self::unframed(response()->view('app-auth.page', [
                'title' => 'Sign-in is unavailable',
                'message' => 'This account is scheduled for deletion. Sign in on the WxNotify website to cancel the deletion first.',
            ], 403));
        }

        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('filament.dashboard.auth.email-verification.prompt');
        }

        return null;
    }

    /**
     * The consent page must never be embedded in another site's frame (clickjacking the Allow button).
     */
    public static function unframed(Response $response): Response
    {
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");

        return $response;
    }
}
