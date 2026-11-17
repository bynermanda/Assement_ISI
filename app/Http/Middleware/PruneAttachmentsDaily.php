<?php

namespace App\Http\Middleware;

use App\Support\Attachments;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

use function Illuminate\Support\defer;

/**
 * Render's free plan has no cron: the first request of the day runs the attachment retention (Attachments::prune)
 * after its response has been sent. On a server with cron, `php artisan schedule:run` does the same (routes/console.php).
 */
class PruneAttachmentsDaily
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Attachments::enabled() && Cache::add('lampiran:prune:' . now()->toDateString(), true, now()->addDay())) {
            defer(function () {
                try {
                    Attachments::prune();
                } catch (\Throwable $e) {
                    Log::warning('Pembersihan lampiran gagal', ['error' => $e->getMessage()]);
                }
            });
        }

        return $next($request);
    }
}
