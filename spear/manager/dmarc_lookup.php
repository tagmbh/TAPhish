<?php
/**
 * Phase 3.41: pre-engagement DMARC / SPF / DKIM posture analyzer.
 *
 * Given a target domain, return the email-authentication posture
 * (SPF, DMARC, MX) plus an operator-facing recommendation:
 *
 *   - p=reject DMARC + restrictive SPF -> direct spoofing is out,
 *     pivot to a look-alike domain (see homoglyph helper).
 *   - p=none / no DMARC -> direct From: spoofing may bypass; consider
 *     it but assume the recipient gateway flags external+spoofed
 *     anyway.
 *   - Missing SPF entirely -> rarely seen, almost always a soft target.
 *
 * The recommendation engine is pure (operates on already-fetched
 * DNS records). DNS resolution itself is wrapped by a tiny injectable
 * resolver so the unit suite can hand it a fixture.
 */

if (!function_exists('taphish_dmarc_parse_record')) {
    /**
     * Parse a `v=DMARC1; p=reject; rua=mailto:…; pct=100;` style
     * record into a normalized assoc array (lower-case tag names).
     * Tolerates extra whitespace + missing values.
     */
    function taphish_dmarc_parse_record(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];
        $out = [];
        foreach (explode(';', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || !str_contains($part, '=')) continue;
            [$k, $v] = explode('=', $part, 2);
            $out[strtolower(trim($k))] = trim($v);
        }
        return $out;
    }
}

if (!function_exists('taphish_spf_parse_record')) {
    /**
     * Parse "v=spf1 include:_spf.example.org -all" into:
     *   ['mechanisms' => [...], 'qualifier_all' => '-'|'~'|'?'|'+'|null]
     */
    function taphish_spf_parse_record(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return ['mechanisms' => [], 'qualifier_all' => null];
        $tokens = preg_split('/\s+/', $raw);
        $mechs = [];
        $qual_all = null;
        foreach ($tokens as $t) {
            if ($t === '' || strcasecmp($t, 'v=spf1') === 0) continue;
            if (preg_match('/^([+\-~?])?all$/i', $t, $m)) {
                $qual_all = $m[1] !== '' ? $m[1] : '+';
                continue;
            }
            $mechs[] = $t;
        }
        return ['mechanisms' => $mechs, 'qualifier_all' => $qual_all];
    }
}

if (!function_exists('taphish_email_posture_recommendation')) {
    /**
     * Pure rule-set: given parsed SPF + DMARC, return one of the
     * canonical operator recommendations.
     */
    function taphish_email_posture_recommendation(array $spf, array $dmarc): array
    {
        $has_spf  = !empty($spf['qualifier_all']) || !empty($spf['mechanisms']);
        $has_dmarc = !empty($dmarc);
        $dmarc_p = $dmarc['p'] ?? '';
        $dmarc_p = strtolower($dmarc_p);

        if ($has_dmarc && $dmarc_p === 'reject') {
            return [
                'verdict'        => 'hardened',
                'recommendation' => 'DMARC p=reject — direct From-spoofing of @<target> will be dropped at the recipient gateway. Pivot to a look-alike domain (see Homoglyph tool) and craft From: matching the look-alike.',
            ];
        }
        if ($has_dmarc && $dmarc_p === 'quarantine') {
            return [
                'verdict'        => 'partially-hardened',
                'recommendation' => 'DMARC p=quarantine — direct spoofing usually lands in Junk. Prefer a look-alike. If you must use the real domain, consider an authenticated relay through a compromised legit sender.',
            ];
        }
        if ($has_dmarc && $dmarc_p === 'none') {
            return [
                'verdict'        => 'monitoring',
                'recommendation' => 'DMARC p=none — the target is collecting telemetry only. Direct spoofing may land in the inbox depending on the recipient gateway. Worth a try AND keep a look-alike as fallback.',
            ];
        }
        if (!$has_dmarc && $has_spf && $spf['qualifier_all'] === '-') {
            return [
                'verdict'        => 'spf-only-strict',
                'recommendation' => 'SPF -all but no DMARC — many gateways still accept the message because SPF failure alone is not always rejected. Direct spoof attempts may bypass, especially via header tricks. Look-alike is still safer.',
            ];
        }
        if (!$has_dmarc && !$has_spf) {
            return [
                'verdict'        => 'wide-open',
                'recommendation' => 'No SPF, no DMARC — direct From-spoofing is the obvious move. Rare in practice; double-check by re-running the lookup.',
            ];
        }
        return [
            'verdict'        => 'unknown',
            'recommendation' => 'Inconclusive — review the raw SPF/DMARC records and decide manually.',
        ];
    }
}

if (!function_exists('taphish_lookup_email_posture')) {
    /**
     * Fetch SPF + DMARC + MX for $domain and return the posture +
     * recommendation. The resolver is injectable so tests can pass
     * a fixture; if $resolver is null the production path uses
     * dns_get_record().
     *
     * Resolver signature: function (string $host, int $type): array
     *   $type is one of the DNS_* constants (DNS_TXT, DNS_MX).
     *   Returns the same shape dns_get_record() does.
     */
    function taphish_lookup_email_posture(string $domain, ?callable $resolver = null): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return ['ok' => false, 'error' => 'empty domain'];
        }
        $resolver = $resolver ?? function (string $host, int $type) {
            return @dns_get_record($host, $type) ?: [];
        };

        $apex_txt   = $resolver($domain, DNS_TXT);
        $dmarc_txt  = $resolver('_dmarc.' . $domain, DNS_TXT);
        $mx         = $resolver($domain, DNS_MX);

        $spf_raw = '';
        foreach ($apex_txt as $r) {
            $t = (string)($r['txt'] ?? '');
            if (str_starts_with(strtolower($t), 'v=spf1')) {
                $spf_raw = $t;
                break;
            }
        }
        $dmarc_raw = '';
        foreach ($dmarc_txt as $r) {
            $t = (string)($r['txt'] ?? '');
            if (str_starts_with(strtolower($t), 'v=dmarc1')) {
                $dmarc_raw = $t;
                break;
            }
        }
        $mx_hosts = array_map(function ($r) {
            return (string)($r['target'] ?? '');
        }, $mx);

        $spf_parsed   = taphish_spf_parse_record($spf_raw);
        $dmarc_parsed = taphish_dmarc_parse_record($dmarc_raw);
        $reco         = taphish_email_posture_recommendation($spf_parsed, $dmarc_parsed);

        return [
            'ok'             => true,
            'domain'         => $domain,
            'spf_raw'        => $spf_raw,
            'spf'            => $spf_parsed,
            'dmarc_raw'      => $dmarc_raw,
            'dmarc'          => $dmarc_parsed,
            'mx_hosts'       => $mx_hosts,
            'verdict'        => $reco['verdict'],
            'recommendation' => $reco['recommendation'],
        ];
    }
}
