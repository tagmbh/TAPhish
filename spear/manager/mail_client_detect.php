<?php
/**
 * Mail client / browser detection from a User-Agent string — pure helper.
 *
 * Extracted from common_functions.php::getMailClient() (which now delegates
 * here) so the per-pattern matching contract can be unit-tested and the
 * order-sensitive quirks made visible. The function is used to surface
 * "what client did the recipient open the mail in?" in the campaign
 * dashboard (tb_data_mailcamp_live.browser column on opens + clicks).
 *
 * Behavioural quirks (PINNED by tests so any future re-order is deliberate):
 *
 *   1. The loop does NOT break on first match — the LAST matching pattern
 *      wins. With the current ordering, a modern Safari User-Agent
 *      ("Mozilla/5.0 (Macintosh; …) AppleWebKit/… Version/… Safari/…")
 *      matches both /safari/i and /Macintosh.*AppleWebKit/i, so the
 *      Macintosh-AppleWebKit pattern overrides Safari → the dashboard
 *      reports "Apple Mail" for a real Safari open on a Mac. Fixable by
 *      adding a "Version/" guard to the AppleWebKit pattern OR by short-
 *      circuiting on first match + reordering most-specific-first.
 *
 *   2. Chrome and Edge both contain "Chrome" + "Safari" in their UAs;
 *      because /edge/i comes AFTER /chrome/i, Edge correctly overrides.
 *
 *   3. An unknown UA yields the literal string 'unknown'.
 */

if (!function_exists('taphish_mail_client_patterns')) {
    /**
     * Ordered list of [regex => label] pairs. ORDER MATTERS — see quirk #1.
     *
     * @return array<string, string>
     */
    function taphish_mail_client_patterns(): array
    {
        return [
            '/msie|trident/i'              => 'Internet Explorer',
            '/firefox/i'                   => 'Firefox',
            '/safari/i'                    => 'Safari',
            '/Macintosh.*AppleWebKit/i'    => 'Apple Mail',
            '/chrome/i'                    => 'Chrome',
            '/edge/i'                      => 'Edge',
            '/opera/i'                     => 'Opera',
            '/netscape/i'                  => 'Netscape',
            '/maxthon/i'                   => 'Maxthon',
            '/konqueror/i'                 => 'Konqueror',
            '/mobile/i'                    => 'Handheld Browser',
            '/Microsoft Outlook|MSOffice/i' => 'Microsoft Outlook',
            '/GoogleImageProxy/i'          => 'Gmail',
            '/Thunderbird/i'               => 'Thunderbird',
            '/YahooMobile/i'               => 'Yahoo Mobile Mail',
            '/Lotus-Notes/i'               => 'IBM Lotus Notes',
            '/Roundcube/i'                 => 'Roundcube',
            '/Horde/i'                     => 'Horde',
        ];
    }
}

if (!function_exists('taphish_mail_client_from_ua')) {
    function taphish_mail_client_from_ua(string $user_agent): string
    {
        $label = 'unknown';
        foreach (taphish_mail_client_patterns() as $regex => $value) {
            if (preg_match($regex, $user_agent)) {
                $label = $value; // LAST match wins — see quirk #1 in file header
            }
        }
        return $label;
    }
}
