<?php

use App\Support\Ui;

if (! function_exists('t')) {
    /** Interface text: Indonesian, plus Traditional Chinese for a2.* accounts. See App\Support\Ui. */
    function t(string $id, array $replace = []): string
    {
        return Ui::t($id, $replace);
    }
}

if (! function_exists('tb')) {
    /** Indonesian with the Chinese on a second, smaller line (a2.* accounts): for headings and sentences. */
    function tb(string $id, array $replace = []): \Illuminate\Support\HtmlString
    {
        return Ui::block($id, $replace);
    }
}

if (! function_exists('tzh')) {
    /** Only the Chinese line (empty for accounts that do not see Chinese). */
    function tzh(string $id, array $replace = []): string
    {
        return Ui::zh($id, $replace);
    }
}
