<?php

namespace App\Support;

use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;

/**
 * Writes a rendered export view into mPDF in small pieces.
 *
 * mPDF refuses one WriteHTML() string longer than pcre.backtrack_limit (1 MB by default), and a long recap
 * (e.g. 24 months x 650 employees) renders to several MB. mPDF keeps its parser state between calls when
 * $init / $close are false, so a big table can be fed row by row: the CSS goes in once (HEADER_CSS),
 * then the body is cut after a </tr> (or a </table>) every ~CHUNK bytes. The result is the same PDF.
 */
final class PdfWriter
{
    private const CHUNK = 200_000;

    public static function write(Mpdf $mpdf, string $html): void
    {
        if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $styles)) {
            $mpdf->WriteHTML(implode("\n", $styles[1]), HTMLParserMode::HEADER_CSS);
        }
        $start = stripos($html, '<body');
        $body  = $start === false ? $html : substr($html, strpos($html, '>', $start) + 1);
        if (($end = strripos($body, '</body>')) !== false) {
            $body = substr($body, 0, $end);
        }
        $body = preg_replace('#<style\b[^>]*>.*?</style>#is', '', $body);
        unset($html, $styles);

        $chunks = self::split($body);
        unset($body);
        $last = count($chunks) - 1;
        foreach ($chunks as $i => $chunk) {
            $mpdf->WriteHTML($chunk, HTMLParserMode::HTML_BODY, $i === 0, $i === $last);
            unset($chunks[$i]);
            if ($i % 10 === 9) {
                gc_collect_cycles();
            }
        }
    }

    /** @return list<string> pieces of about CHUNK bytes, each ending right after a closing </tr> or </table>. */
    private static function split(string $body): array
    {
        $chunks = [];
        $from   = 0;
        $length = strlen($body);
        while ($length - $from > self::CHUNK) {
            $cut = self::cutAfter($body, $from + self::CHUNK);
            if ($cut === null) {
                break;
            }
            $chunks[] = substr($body, $from, $cut - $from);
            $from     = $cut;
        }
        $chunks[] = substr($body, $from);

        return $chunks;
    }

    private static function cutAfter(string $body, int $offset): ?int
    {
        $tr    = stripos($body, '</tr>', $offset);
        $table = stripos($body, '</table>', $offset);
        $hits  = array_filter([$tr === false ? null : $tr + 5, $table === false ? null : $table + 8]);

        return $hits ? min($hits) : null;
    }
}
