<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Browser-side defences on every web response.
 *
 * CSP note: the pages use the Tailwind CDN (injects <style>) and Alpine.js (evaluates x-* expressions), plus
 * small inline <script> blocks, so 'unsafe-inline' / 'unsafe-eval' are still needed. The policy still blocks
 * scripts from any other origin, plugins, framing by other sites and form posts to other sites.
 * Moving Tailwind / Alpine into a Vite build (and Alpine's CSP build) would allow a stricter policy.
 */
class SecurityHeaders
{
    private const CSP = [
        "default-src 'self'",
        "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdn.jsdelivr.net",
        "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com",   // Inter (SF Pro substitute off Apple devices)
        "img-src 'self' data:",
        "font-src 'self' data: https://fonts.gstatic.com",
        "connect-src 'self'",
        "object-src 'none'",
        "base-uri 'self'",
        "form-action 'self'",
        "frame-ancestors 'self'",
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $headers = $response->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('X-Frame-Options', 'SAMEORIGIN');
        $headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        // HTML pages only (downloads such as Excel / PDF do not need it)
        if (str_contains((string) $headers->get('Content-Type'), 'text/html')) {
            $headers->set('Content-Security-Policy', implode('; ', self::CSP));
        }
        if ($request->isSecure()) {
            $headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
