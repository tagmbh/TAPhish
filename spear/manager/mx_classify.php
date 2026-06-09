<?php
/**
 * Phase 3.43b: classify a domain's mail provider from its MX record set.
 *
 * The provider answers a hard pre-engagement question: which pretext set
 * is most likely to land. M365 → use the M365 login pretext + clone the
 * M365 portal. Google Workspace → use the Gmail / Drive variants. Vendor
 * MX (Proofpoint, Mimecast, Cisco IronPort) → expect URL rewriting and
 * pick anti-scanner-friendly options.
 *
 * Pure on the classifier; the DNS lookup is injectable so tests don't
 * need a network.
 */

if (!function_exists('taphish_mx_provider_table')) {
    function taphish_mx_provider_table(): array
    {
        // Each row: [needle (in MX target), provider key, human label, category].
        // Order matters — more specific needles first.
        return [
            // Microsoft 365 (Exchange Online).
            ['.mail.protection.outlook.com', 'm365',          'Microsoft 365 (Exchange Online)',  'cloud-mailbox'],
            ['.protection.outlook.com',      'm365',          'Microsoft 365 (Exchange Online)',  'cloud-mailbox'],
            ['outlook.com',                  'm365',          'Microsoft 365 (Exchange Online)',  'cloud-mailbox'],
            // Google Workspace.
            ['aspmx.l.google.com',           'google',        'Google Workspace',                 'cloud-mailbox'],
            ['googlemail.com',               'google',        'Google Workspace',                 'cloud-mailbox'],
            ['google.com',                   'google',        'Google Workspace',                 'cloud-mailbox'],
            // Proofpoint Essentials / Enterprise.
            ['ppe-hosted.com',               'proofpoint',    'Proofpoint Essentials',            'security-gateway'],
            ['pphosted.com',                 'proofpoint',    'Proofpoint Enterprise',            'security-gateway'],
            // Mimecast.
            ['mimecast.com',                 'mimecast',      'Mimecast Email Security',          'security-gateway'],
            // Cisco IronPort / Secure Email Gateway.
            ['iphmx.com',                    'cisco-ses',     'Cisco Secure Email Gateway',       'security-gateway'],
            ['cisco.com',                    'cisco-ses',     'Cisco Secure Email Gateway',       'security-gateway'],
            // Barracuda.
            ['barracudanetworks.com',        'barracuda',     'Barracuda Email Security',         'security-gateway'],
            // Trend Micro.
            ['trendmicro.com',               'trend',         'Trend Micro Email Security',       'security-gateway'],
            // FireEye / Trellix.
            ['fireeyecloud.com',             'fireeye',       'Trellix (FireEye) Email Security', 'security-gateway'],
            // Sophos.
            ['sophos.com',                   'sophos',        'Sophos Email Security',            'security-gateway'],
            // Hostpoint (CH).
            ['hostpoint.ch',                 'hostpoint',     'Hostpoint (CH shared hosting)',    'shared-host'],
            // Infomaniak (CH).
            ['infomaniak.ch',                'infomaniak',    'Infomaniak (CH)',                  'shared-host'],
            // Cyon (CH).
            ['cyon.ch',                      'cyon',          'Cyon (CH)',                        'shared-host'],
            // Zoho.
            ['zoho.com',                     'zoho',          'Zoho Mail',                        'cloud-mailbox'],
            ['zoho.eu',                      'zoho',          'Zoho Mail (EU)',                   'cloud-mailbox'],
            // Fastmail.
            ['messagingengine.com',          'fastmail',      'Fastmail',                         'cloud-mailbox'],
            // Mailgun.
            ['mailgun.org',                  'mailgun',       'Mailgun',                          'cloud-mailbox'],
            // ProtonMail / Proton.
            ['protonmail.ch',                'proton',        'Proton Mail',                      'cloud-mailbox'],
            ['proton.me',                    'proton',        'Proton Mail',                      'cloud-mailbox'],
            ['protonmail.com',               'proton',        'Proton Mail',                      'cloud-mailbox'],
        ];
    }
}

if (!function_exists('taphish_mx_classify_record')) {
    /**
     * Classify a single MX target string against the provider table.
     * Returns provider/label/category or 'unknown' if no needle matches.
     */
    function taphish_mx_classify_record(string $mxTarget): array
    {
        $target = strtolower(trim(rtrim($mxTarget, '.')));
        foreach (taphish_mx_provider_table() as [$needle, $key, $label, $category]) {
            if ($target !== '' && str_contains($target, $needle)) {
                return ['provider' => $key, 'label' => $label, 'category' => $category];
            }
        }
        return ['provider' => 'unknown', 'label' => $target !== '' ? $target : '—', 'category' => 'unknown'];
    }
}

if (!function_exists('taphish_mx_summarise')) {
    /**
     * Summarise an MX record set into a single verdict. If any record
     * matches a security-gateway provider, the gateway wins (because that
     * controls what the recipient sees). Otherwise the most-frequent
     * cloud-mailbox provider wins. Ties broken by appearance order.
     *
     * @param string[] $mxTargets MX target hostnames as returned by DNS.
     */
    function taphish_mx_summarise(array $mxTargets): array
    {
        $records = [];
        $counts  = [];
        $first   = [];

        foreach ($mxTargets as $idx => $t) {
            $c = taphish_mx_classify_record((string) $t);
            $records[] = ['target' => $t] + $c;
            if ($c['provider'] === 'unknown') {
                continue;
            }
            $counts[$c['provider']] = ($counts[$c['provider']] ?? 0) + 1;
            if (!isset($first[$c['provider']])) {
                $first[$c['provider']] = $idx;
            }
        }

        $primary = ['provider' => 'unknown', 'label' => 'No MX records found', 'category' => 'unknown'];

        if (!empty($counts)) {
            // Gateways win.
            $best = null;
            foreach ($counts as $prov => $n) {
                $cat = null;
                foreach ($records as $r) {
                    if ($r['provider'] === $prov) { $cat = $r['category']; break; }
                }
                if ($cat === 'security-gateway') {
                    if ($best === null || $first[$prov] < $first[$best]) {
                        $best = $prov;
                    }
                }
            }
            if ($best === null) {
                // Then by frequency, ties broken by first appearance.
                arsort($counts);
                foreach ($counts as $prov => $n) {
                    if ($best === null) { $best = $prov; continue; }
                    if ($counts[$prov] === $counts[$best] && $first[$prov] < $first[$best]) {
                        $best = $prov;
                    }
                }
            }
            foreach ($records as $r) {
                if ($r['provider'] === $best) {
                    $primary = ['provider' => $r['provider'], 'label' => $r['label'], 'category' => $r['category']];
                    break;
                }
            }
        }

        return [
            'primary' => $primary,
            'records' => $records,
            'count'   => count($records),
        ];
    }
}

if (!function_exists('taphish_mx_recommend_pretexts')) {
    /**
     * Suggest which pretext categories to surface first based on the
     * detected MX provider. Pure: returns an ordered list of category
     * keys matching the Phase 3.39 pretext library taxonomy.
     */
    function taphish_mx_recommend_pretexts(array $summary): array
    {
        $prov = $summary['primary']['provider'] ?? 'unknown';
        switch ($prov) {
            case 'm365':
                return ['Authentication', 'IT', 'HR'];
            case 'google':
                return ['Authentication', 'IT', 'HR'];
            case 'proofpoint':
            case 'mimecast':
            case 'cisco-ses':
            case 'barracuda':
            case 'trend':
            case 'fireeye':
            case 'sophos':
                // Gateways usually front M365 or Google → still lean Auth.
                return ['Authentication', 'IT', 'Shipping'];
            case 'proton':
                return ['Authentication', 'IT'];
            case 'zoho':
            case 'fastmail':
            case 'hostpoint':
            case 'infomaniak':
            case 'cyon':
                return ['IT', 'Authentication', 'Finance'];
            default:
                return ['Authentication', 'IT', 'Finance', 'HR', 'Shipping'];
        }
    }
}

if (!function_exists('taphish_mx_lookup')) {
    /**
     * Resolve $domain → list of MX target hostnames (no priorities).
     * $resolver is a callable for unit tests that returns the array of
     * DNS records like dns_get_record(). Falls back to dns_get_record on
     * null. Returns [] if anything goes wrong (DNS failure, etc).
     */
    function taphish_mx_lookup(string $domain, ?callable $resolver = null): array
    {
        $domain = strtolower(trim(rtrim($domain, '.')));
        if ($domain === '') {
            return [];
        }
        $records = $resolver
            ? $resolver($domain)
            : @dns_get_record($domain, DNS_MX);
        $targets = [];
        if (is_array($records)) {
            foreach ($records as $r) {
                if (isset($r['target']) && is_string($r['target']) && $r['target'] !== '') {
                    $targets[] = $r['target'];
                }
            }
        }
        // Fallback: on a number of shared/container hosts dns_get_record(...,
        // DNS_MX) returns false/empty even when the domain has MX records,
        // while getmxrr() (a different resolver path) succeeds. DNS_TXT works
        // on those hosts, which is why the DMARC lane looks fine but MX shows
        // "no records". Only in the live path (no injected resolver) so the
        // unit suite stays deterministic.
        if (!$targets && $resolver === null) {
            $hosts = [];
            if (@getmxrr($domain, $hosts) && !empty($hosts)) {
                foreach ($hosts as $h) {
                    if (is_string($h) && $h !== '') {
                        $targets[] = $h;
                    }
                }
            }
        }
        return $targets;
    }
}

if (!function_exists('taphish_mx_classify_domain')) {
    /**
     * End-to-end: lookup MX for $domain, summarise, recommend pretexts.
     * Returns the full envelope the OSINT panel renders.
     */
    function taphish_mx_classify_domain(string $domain, ?callable $resolver = null): array
    {
        $targets = taphish_mx_lookup($domain, $resolver);
        $summary = taphish_mx_summarise($targets);
        return [
            'domain'              => $domain,
            'primary'             => $summary['primary'],
            'records'             => $summary['records'],
            'count'               => $summary['count'],
            'pretext_categories'  => taphish_mx_recommend_pretexts($summary),
        ];
    }
}
