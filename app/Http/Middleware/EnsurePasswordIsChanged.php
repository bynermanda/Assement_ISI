<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Users whose password was just created or reset by an admin must choose
 * their own password before they can use the application.
 */
class EnsurePasswordIsChanged
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->must_change_password) {
            return redirect()
                ->route('password.edit')
                ->with('error', t('Silakan buat password baru sebelum melanjutkan.'));
        }

        return $next($request);
    }
}
