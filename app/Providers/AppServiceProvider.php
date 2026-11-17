<?php

namespace App\Providers;

use App\Database\PooledPostgresConnection;
use App\Models\A2Assessment;
use App\Models\A2Participant;
use App\Models\Assessment;
use App\Models\Employee;
use App\Models\User;
use App\Support\RefCache;
use App\Support\ReviewRules;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // pgsql through the Supabase pooler: see App\Database\PooledPostgresConnection
        Connection::resolverFor('pgsql', fn ($pdo, $database, $prefix, $config) => new PooledPostgresConnection($pdo, $database, $prefix, $config));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // assessment_attachments.attachable_type stores these short names, not class names
        Relation::morphMap(['a1' => Assessment::class, 'a2' => A2Assessment::class]);

        // ---- RefCache invalidation: every model write of cached reference data bumps its group
        //      (bulk writes that bypass models flush explicitly: employee import, account merge/split, a2:import, guidelines)
        // "routes" (the computed Assessment 1 special routes) depends on employees, accounts and Assessment 2 participants
        $employees = function () { RefCache::flush('employees', 'routes'); ReviewRules::forgetActive(); };
        Employee::saved($employees);
        Employee::deleted($employees);
        $a2 = function () { RefCache::flush('a2', 'routes'); ReviewRules::forgetActive(); };
        A2Participant::saved($a2);
        A2Participant::deleted($a2);
        User::saved($a2);
        User::deleted($a2);
        $routes = function () { RefCache::flush('routes'); ReviewRules::forgetActive(); };
        \App\Models\A1Route::saved($routes);
        \App\Models\A1Route::deleted($routes);

        // ---- Rate limits (per account, or per IP before login)
        $who = fn (Request $request) => $request->user()?->id ? 'u' . $request->user()->id : 'ip' . $request->ip();

        // Login: per username + IP (a whole factory can share one IP) and a wider cap per IP
        RateLimiter::for('login', fn (Request $request) => [
            Limit::perMinute(5)->by(strtolower(trim((string) $request->input('username'))) . '|' . $request->ip()),
            Limit::perMinute(60)->by('ip' . $request->ip()),
        ]);
        // Every POST / PUT / PATCH / DELETE of a logged-in user: normal work stays far below this
        RateLimiter::for('writes', fn (Request $request) => $request->isMethodSafe()
            ? Limit::none()
            : Limit::perMinute(60)->by($who($request))->response(fn (Request $request, array $headers) => $request->expectsJson()
                ? response()->json(['message' => t('Terlalu banyak aksi. Coba lagi dalam satu menit.')], 429, $headers)
                : back()->with('error', t('Terlalu banyak aksi. Coba lagi dalam satu menit.'))));
        // Assessment Massal: one request saves a whole section
        RateLimiter::for('bulk', fn (Request $request) => Limit::perMinute(10)->by($who($request)));
        // Excel / PDF exports are heavy (PDF ~2 s of CPU)
        RateLimiter::for('exports', fn (Request $request) => Limit::perMinute(10)->by($who($request)));
    }
}
