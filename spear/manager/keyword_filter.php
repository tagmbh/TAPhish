<?php
/**
 * Merge-token / placeholder substitution — pure helper.
 *
 * Extracted from common_functions.php::filterKeywords() (which now delegates
 * here) so the substitution contract can be unit-tested. Two substitution
 * passes:
 *
 *   1. Known tokens: every entry in KNOWN_TOKENS (e.g. {{FNAME}}, {{EMAIL}},
 *      {{TRACKINGURL}}, …) is replaced with the matching value from the
 *      caller-supplied $keyword_vals map. Case-insensitive (str_ireplace).
 *      Missing/null values become empty strings — by design, so a partially-
 *      populated row doesn't ship literal `{{EMAIL}}` text to the recipient.
 *
 *   2. {{RND}} / {{RNDn}} random-string tokens: every occurrence is replaced
 *      with an independently-generated random string of the requested length
 *      (default 5 when no digits follow). The generator is INJECTABLE — a
 *      test passes its own deterministic stub; production passes
 *      getRandomStr (the existing alpha-num generator in common_functions).
 *
 * Substitution is intentionally NOT html-escaped. Caller controls every value
 * (recipient list, merge-token map) and may legitimately want HTML in
 * {{NOTES}} or a real `<` in a name. The XSS surface that matters is the
 * cloned landing page (whose own escaping is the campaign-clone phase's job).
 */

if (!function_exists('taphish_filter_keyword_known_tokens')) {
    /**
     * The fixed list of known tokens this engine substitutes. Order matters
     * only when one token's value contains another's literal text, which the
     * codebase never relies on. Kept in sync with the original switch in
     * common_functions.php::filterKeywords.
     *
     * @return string[]
     */
    function taphish_filter_keyword_known_tokens(): array
    {
        return [
            '{{RID}}', '{{MID}}', '{{NAME}}', '{{FNAME}}', '{{LNAME}}',
            '{{NOTES}}', '{{EMAIL}}', '{{FROM}}', '{{TRACKINGURL}}',
            '{{TRACKER}}', '{{BASEURL}}', '{{MUSERNAME}}', '{{MDOMAIN}}',
        ];
    }
}

if (!function_exists('taphish_filter_keywords')) {
    /**
     * @param string $content      the template body / subject / cell content
     * @param array<string,?string> $keyword_vals
     *                             token => substitution-value map. Missing
     *                             entries are treated as empty string.
     * @param ?callable $randStrFn fn(int $length): string — random-string
     *                             generator. Defaults to getRandomStr() if
     *                             that global exists; tests inject their own.
     */
    function taphish_filter_keywords(string $content, array $keyword_vals, ?callable $randStrFn = null): string
    {
        // Pass 1 — known tokens
        foreach (taphish_filter_keyword_known_tokens() as $token) {
            $val = $keyword_vals[$token] ?? '';
            // str_ireplace coerces null to '' — explicit so a future PHP
            // strictness bump can't change the behaviour.
            $content = str_ireplace($token, (string) $val, $content);
        }

        // Pass 2 — {{RND}} / {{RND<digits>}} random-string tokens. Each MATCH
        // gets its own freshly-generated random string. The regex matches the
        // whole token; we extract the digits for the length.
        if (preg_match_all('/{{RND(\d*)}}/i', $content, $matches)) {
            $tokens  = array_unique($matches[0]); // distinct token strings
            $lengths = [];
            foreach ($matches[0] as $i => $tok) {
                $lengths[$tok] = $matches[1][$i] === '' ? 5 : max(1, (int) $matches[1][$i]);
            }
            $fn = $randStrFn ?? (function_exists('getRandomStr')
                ? static fn (int $n): string => getRandomStr($n)
                : static fn (int $n): string => substr(str_pad('', $n, 'X'), 0, $n));
            foreach ($tokens as $tok) {
                $len = $lengths[$tok];
                $content = str_ireplace($tok, $fn($len), $content);
            }
        }

        return $content;
    }
}
