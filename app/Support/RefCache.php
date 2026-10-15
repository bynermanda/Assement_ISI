<?php

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * Cache for reference data that is read on almost every page but rarely changes (employee list, guidelines,
 * Assessment 2 duties). The database is far away (Supabase, ~0.3-0.5 s per query), so every query saved counts.
 *
 * Each group has a version number; flush($group) bumps it, so all keys of that group are ignored at once.
 * Writes call flush() (model events in AppServiceProvider, and the bulk writes that bypass models).
 * The TTL is only a safety net.
 *
 * Groups: employees · guidelines · a2
 */
final class RefCache
{
    private const TTL = 600;   // seconds

    public static function remember(string $group, string $key, Closure $callback): mixed
    {
        return Cache::remember("ref:{$group}:v" . self::version($group) . ":{$key}", self::TTL, $callback);
    }

    public static function flush(string ...$groups): void
    {
        foreach ($groups as $group) {
            Cache::forever("ref:{$group}:version", self::version($group) + 1);
        }
    }

    private static function version(string $group): int
    {
        return (int) Cache::get("ref:{$group}:version", 1);
    }
}
