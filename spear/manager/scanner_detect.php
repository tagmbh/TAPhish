<?php
/**
 * Phase 3.40: detect URL-scanner traffic on tracker + landing endpoints.
 *
 * M365 SafeLinks, Proofpoint URL Defense, Mimecast, Cisco Umbrella,
 * Barracuda, and a long tail of inline AV / secure-gateway products
 * GET every URL in every inbound email seconds after delivery. Each
 * hit currently pollutes engagement metrics and — worse — can burn
 * the landing-page URL before the real recipient ever opens the
 * message.
 *
 * The classifier is pure: no DB, no $_SERVER reads, no session. The
 * caller passes UA + IP + seconds-since-send and receives a verdict
 * of 'real' / 'scanner' / 'suspect'. Behaviour at the call site:
 *
 *   real     -> proceed, count as engagement
 *   scanner  -> serve a benign 200 ("Loading...") and DO NOT count
 *   suspect  -> count as engagement but flag for review
 *
 * The downstream wiring in track.php / qt.php / mod.php is in a
 * sibling commit; the classifier lives here so the unit suite can
 * test every signal in isolation.
 */

if (!function_exists('taphish_scanner_ua_substrings')) {
    /**
     * UA tokens emitted by well-known URL-rewriting / inline-AV
     * products. Lower-case substring match. Extending the list is a
     * one-line append.
     */
    function taphish_scanner_ua_substrings(): array
    {
        return [
            'safelinks',          // Microsoft 365 SafeLinks
            'urldefense',         // Proofpoint URL Defense
            'mimecast',           // Mimecast Targeted Threat Protection
            'barracuda',          // Barracuda Email Gateway
            'trustwave',          // Trustwave SEG
            'forcepoint',         // Forcepoint Web Gateway
            'symanteccloud',      // Symantec Email Security.cloud
            'cisco-ironport',     // Cisco IronPort
            'fireeye',            // FireEye EX
            'umbrella',           // Cisco Umbrella
            'sophosmsf',          // Sophos Email
            'spambrella',         // Spambrella
            'bitdefender',        // Bitdefender GravityZone
            'agari',              // Agari Defense
            'avanan',             // Avanan / Check Point
            'slack-imgproxy',     // Slack link preview
            'twitterbot',         // Twitter/X URL expansion
            'discordbot',         // Discord link preview
            'telegrambot',        // Telegram link preview
            'whatsapp',           // WhatsApp link preview
            'facebookexternalhit', // Facebook link preview
            'linkedinbot',        // LinkedIn link preview
            'googlebot',          // Generic crawler (unlikely in phish path)
            'bingbot',            // Bing crawler
            'yandex',             // Yandex
            'duckduckbot',        // DuckDuckBot
            'archive.org_bot',    // Wayback
            'crawler',            // Generic
            'spider',             // Generic
        ];
    }
}

if (!function_exists('taphish_scanner_ip_ptr_substrings')) {
    /**
     * Reverse-DNS PTR substrings that indicate datacenter origin.
     * Real recipients almost never come from these — most consumer
     * ISPs and corporate proxies have a residential / corporate PTR.
     */
    function taphish_scanner_ip_ptr_substrings(): array
    {
        return [
            'amazonaws.com',          // AWS EC2 / Lambda
            'compute.amazonaws',      // AWS compute
            'azure',                  // Azure
            'azurewebsites.net',      // Azure App Service
            'cloudfront.net',         // AWS CloudFront
            'googleusercontent.com',  // GCP / Google
            'google-proxy',           // Google proxy
            'oraclecloud.com',        // OCI
            'digitalocean.com',       // DO
            'linode.com',             // Linode
            'vultr.com',              // Vultr
            'hetzner.com',            // Hetzner
            'ovh.net',                // OVH
            'contabo.com',            // Contabo
            'datacamp.uk',            // CDN77 / DataCamp
            'm247.com',               // M247
            'censys-scanner',         // Censys scanner
            'shodan.io',              // Shodan
            'binaryedge.io',          // BinaryEdge
            'leakix.net',             // LeakIX
            'rapid7.com',             // Rapid7 Project Sonar
            'security.netcraft.com',  // Netcraft
        ];
    }
}

if (!function_exists('taphish_classify_visitor')) {
    /**
     * Return the verdict for a single tracker / landing hit.
     *
     *  $ua          User-Agent header. Empty string is treated as
     *               "missing" and falls under 'suspect'.
     *  $ip_ptr      Reverse-DNS PTR for the source IP, lower-case.
     *               Empty string means PTR lookup failed or wasn't
     *               attempted; the IP check is then skipped.
     *  $secondsSinceSend
     *               Wall-clock distance between the campaign's send
     *               time and this hit. <5 s is a high-signal scanner
     *               tell (no human reads + clicks that fast).
     *               Pass -1 to skip the timing check (e.g. when the
     *               send time is unknown).
     */
    function taphish_classify_visitor(
        string $ua,
        string $ip_ptr = '',
        int $secondsSinceSend = -1
    ): array {
        $ua_l   = strtolower(trim($ua));
        $ptr_l  = strtolower(trim($ip_ptr));

        if ($ua_l === '') {
            return ['kind' => 'suspect', 'reason' => 'missing User-Agent'];
        }

        foreach (taphish_scanner_ua_substrings() as $needle) {
            if (str_contains($ua_l, $needle)) {
                return ['kind' => 'scanner', 'reason' => 'UA contains "' . $needle . '"'];
            }
        }

        if ($ptr_l !== '') {
            foreach (taphish_scanner_ip_ptr_substrings() as $needle) {
                if (str_contains($ptr_l, $needle)) {
                    return ['kind' => 'scanner', 'reason' => 'PTR contains "' . $needle . '"'];
                }
            }
        }

        if ($secondsSinceSend >= 0 && $secondsSinceSend < 5) {
            return ['kind' => 'scanner', 'reason' => 'hit within ' . $secondsSinceSend . 's of send'];
        }

        // Generic-shaped UAs that are likely automated but don't carry
        // a known vendor marker. Borderline — flag but still count.
        if (
            str_contains($ua_l, 'python')
            || str_contains($ua_l, 'curl/')
            || str_contains($ua_l, 'wget/')
            || str_contains($ua_l, 'libwww')
            || str_contains($ua_l, 'java/')
            || str_contains($ua_l, 'go-http-client')
            || str_contains($ua_l, 'httpclient')
        ) {
            return ['kind' => 'suspect', 'reason' => 'scripted UA'];
        }

        return ['kind' => 'real', 'reason' => ''];
    }
}

if (!function_exists('taphish_resolve_visitor_ptr')) {
    /**
     * Convenience wrapper for the tracker call sites: take a raw IP
     * (v4 or v6) and return the lower-cased PTR, or empty string on
     * lookup failure. Capped to 1 lookup per request via a static
     * cache so a flurry of hits from the same client doesn't fan out
     * into N DNS queries.
     */
    function taphish_resolve_visitor_ptr(string $ip): string
    {
        static $cache = [];
        $ip = trim($ip);
        if ($ip === '') return '';
        if (isset($cache[$ip])) return $cache[$ip];
        // gethostbyaddr is blocking but fast against a local resolver.
        $ptr = @gethostbyaddr($ip);
        $resolved = ($ptr === false || $ptr === $ip) ? '' : strtolower($ptr);
        $cache[$ip] = $resolved;
        return $resolved;
    }
}
