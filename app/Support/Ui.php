<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Interface language. The pages are written in Indonesian. Accounts whose username starts with "a2." (Assessment 2
 * accounts, many held by TKA) also see Traditional Chinese, from lang/zh_TW.json (keyed by the Indonesian text).
 * Assessment content (criteria, weights, targets, points) is never translated here: it is already bilingual data.
 */
final class Ui
{
    private static ?array $zh = null;

    public static function bilingual(): bool
    {
        $user = auth()->user();

        return $user !== null && str_starts_with(strtolower((string) $user->username), 'a2.');
    }

    /** "Simpan draf" or, for a2.* accounts, "Simpan draf · 儲存草稿". :placeholders are replaced in both. */
    public static function t(string $id, array $replace = []): string
    {
        $text = strtr($id, self::placeholders($replace));

        if (! self::bilingual()) {
            return $text;
        }
        $zh = self::dictionary()[$id] ?? null;

        return $zh === null || $zh === '' ? $text : $text . ' · ' . strtr($zh, self::placeholders($replace));
    }

    /** Only the Chinese part (empty for other accounts): for a second, smaller line under a heading. */
    public static function zh(string $id, array $replace = []): string
    {
        if (! self::bilingual()) {
            return '';
        }

        return strtr(self::dictionary()[$id] ?? '', self::placeholders($replace));
    }

    /** Indonesian, with the Chinese on its own smaller line under it (a2.* accounts). Safe HTML for headings / paragraphs. */
    public static function block(string $id, array $replace = []): HtmlString
    {
        $text = e(strtr($id, self::placeholders($replace)));
        $zh   = self::zh($id, $replace);

        return new HtmlString($zh === '' ? $text : $text . '<span class="block text-[0.85em] font-normal opacity-80" lang="zh-Hant">' . e($zh) . '</span>');
    }

    /** Status of an assessment in words (the database keeps the English value). */
    public static function status(?string $status): string
    {
        return self::t(MyTasks::statusText($status));
    }

    private static function dictionary(): array
    {
        if (self::$zh === null) {
            $file     = lang_path('zh_TW.json');
            self::$zh = is_file($file) ? (json_decode((string) file_get_contents($file), true) ?: []) : [];
        }

        return self::$zh;
    }

    private static function placeholders(array $replace): array
    {
        $out = [];
        foreach ($replace as $key => $value) {
            $out[':' . $key] = (string) $value;
        }

        return $out;
    }
}
