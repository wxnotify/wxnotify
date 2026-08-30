<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectAdmins
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Ensure the user is logged in
        if ($user) {
            // Detect admin users and redirect them to the admin panel
            if ($user->is_staff) {

                // Prevent infinite redirect loops by checking the current path
                if (! str_starts_with($request->getPathInfo(), '/admin')) {
                    return redirect()->to('/admin');
                }
            }
        }

        return $next($request);
    }
}
