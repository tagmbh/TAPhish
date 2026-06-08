<?php
/**
 * URL query-string extraction — pure helper.
 *
 * Extracted from common_functions.php::getQueryValsFromURL() (which now
 * delegates here) so the parsing contract can be unit-tested. The function is
 * called by filterQRBarCode() against every `<img src="?type=qr_b64&content=…">`
 * in a campaign body, and again by the tracker endpoints (tmail.php, qt.php)
 * when interpreting incoming open / click URLs.
 *
 * The implementation passes through:
 *   1. html_entity_decode — incoming URLs that were HTML-encoded in a template
 *      body (e.g. `?mid=X&amp;rid=Y`) are normalised before parsing, so the
 *      caller sees `&` separators not literal `&amp;`.
 *   2. parse_url(..., PHP_URL_QUERY) — strip scheme/host/path, keep just the
 *      query string.
 *   3. parse_str — turn `a=1&b=2&c[]=x&c[]=y` into ['a'=>'1','b'=>'2','c'=>['x','y']].
 *
 * A URL without a query string returns an empty array, never null.
 */

if (!function_exists('taphish_url_query_parse')) {
    /**
     * @return array<string,mixed> parsed query string; empty array when there is none
     */
    function taphish_url_query_parse(string $url): array
    {
        $q = parse_url(html_entity_decode($url), PHP_URL_QUERY);
        if ($q === null || $q === false || $q === '') {
            return [];
        }
        $out = [];
        parse_str($q, $out);
        return $out;
    }
}
