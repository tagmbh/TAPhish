<?php
/**
 * Phase 3.55 — look-alike domain deployment helpers.
 *
 * Pure builders only: emit the copy-paste DNS record set (web + SPF + DKIM +
 * DMARC) for a look-alike domain, and validate vanity slugs. NO DNS writes, no
 * network — TAPhish never touches a registrar. Reuses dkim_helper (SPF/DKIM/
 * DMARC record strings) + domain_check_local_idna (punycode for IDN look-alikes).
 */

require_once(dirname(__FILE__) . '/dkim_helper.php');
require_once(dirname(__FILE__) . '/domain_check.php');

if (!function_exists('lookalike_validate_vanity_slug')) {
    /** Phase 3.55: vanity path slug — lower-alnum start, then alnum/hyphen, max 41 chars. */
    function lookalike_validate_vanity_slug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $slug);
    }
}

if (!function_exists('lookalike_build_dns_records')) {
    /**
     * Phase 3.55: build the advisory DNS record set for a look-alike domain.
     * Returns an ordered list of ['type','host','value','note']. Pure string
     * construction; hosts are punycoded for IDN look-alikes.
     *
     * $opts:
     *   mode         'operator' (A record) | 'hosted' (CNAME). Default 'operator'.
     *   subdomain    optional web subdomain (e.g. 'login'); apex if absent.
     *   a_record     IPv4/IPv6 for operator mode.
     *   cname_target host the CNAME points at for hosted mode (default ptbe...).
     *   selector     DKIM selector (default 's1').
     *   dkim_pubkey  base64 DKIM public key; a <public-key> placeholder if absent.
     *   spf          SPF value (default = dkim_helper suggestion).
     *   dmarc_rua    DMARC rua mailbox (optional).
     */
    function lookalike_build_dns_records(string $domain, array $opts = []): array
    {
        $idna = domain_check_local_idna($domain);
        if ($idna === '') {
            $idna = strtolower(trim($domain));
        }
        if ($idna === '') {
            return [];
        }

        $mode      = ($opts['mode'] ?? 'operator') === 'hosted' ? 'hosted' : 'operator';
        $subdomain = isset($opts['subdomain']) ? trim((string) $opts['subdomain']) : '';
        $selector  = isset($opts['selector']) && taphish_dkim_validate_selector((string) $opts['selector'])
            ? (string) $opts['selector'] : 's1';

        $apex    = $idna . '.';
        $webHost = ($subdomain !== '' ? $subdomain . '.' . $idna : $idna) . '.';

        $records = [];

        // 1) Web record — how the look-alike (sub)domain resolves to the page.
        if ($mode === 'hosted') {
            $target = rtrim(trim((string) ($opts['cname_target'] ?? 'ptbe.autodiscover.li')), '.') . '.';
            $records[] = [
                'type'  => 'CNAME',
                'host'  => $webHost,
                'value' => $target,
                'note'  => 'Points the look-alike at the TAPhish-hosted page. TLS caveat: the '
                         . 'browser sees the host cert, not the look-alike — prefer the raw hosted '
                         . 'URL in the lure, or front it with your own cert.',
            ];
        } else {
            $ip = trim((string) ($opts['a_record'] ?? '')) !== '' ? trim((string) $opts['a_record']) : '<your-webspace-ip>';
            $records[] = [
                'type'  => 'A',
                'host'  => $webHost,
                'value' => $ip,
                'note'  => 'Resolves the look-alike to your webspace where the page bundle is uploaded.',
            ];
        }

        // 2) SPF (apex).
        $spf = isset($opts['spf']) && trim((string) $opts['spf']) !== ''
            ? trim((string) $opts['spf'])
            : taphish_dkim_suggested_spf_record();
        $records[] = [
            'type'  => 'TXT',
            'host'  => $apex,
            'value' => $spf,
            'note'  => 'SPF — authorizes your sending host. Replace the placeholder ip4/ip6 with your sender.',
        ];

        // 3) DKIM (selector._domainkey).
        $pubkey = trim((string) ($opts['dkim_pubkey'] ?? ''));
        $dkimValue = $pubkey !== ''
            ? taphish_dkim_format_txt_record($pubkey)
            : 'v=DKIM1; k=rsa; p=<public-key>';
        $records[] = [
            'type'  => 'TXT',
            'host'  => $selector . '._domainkey.' . $apex,
            'value' => $dkimValue,
            'note'  => 'DKIM — from the key pair generated for this engagement (private key shown once).',
        ];

        // 4) DMARC (_dmarc).
        $records[] = [
            'type'  => 'TXT',
            'host'  => '_dmarc.' . $apex,
            'value' => taphish_dkim_suggested_dmarc_record((string) ($opts['dmarc_rua'] ?? '')),
            'note'  => 'DMARC — starts at p=none while warming; tighten to quarantine/reject later.',
        ];

        return $records;
    }
}
