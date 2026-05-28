<?php
/**
 * Per-recipient timezone-aware scheduling helpers.
 *
 * Design call: the timezone of an arbitrary recipient is inferred from their
 * email's country-code TLD (.de → Europe/Berlin, .ch → Europe/Zurich, …).
 * It's a heuristic — generic TLDs (.com, .org, .net) and a poor match for
 * multinational employees on a country domain — but it requires no schema
 * change and no operator data-entry beyond what TAPhish already has. The
 * fallback for unmappable TLDs is the operator-supplied default zone (the
 * server zone from tb_main_variables).
 *
 * The cron worker uses these helpers to decide whether a recipient is
 * inside their local "send window" right now; recipients outside the
 * window are deferred and the campaign is left in camp_status=5 so the
 * next cron iteration retries.
 *
 * No DB, no session. Tested in tests/RecipientTzTest.php.
 */

if (!function_exists('recipient_tz_tld_map')) {
    /**
     * ccTLD → IANA timezone. Only one zone per country; for big countries
     * we pick the capital / business hub. Operators with very precise
     * needs can change the map here.
     *
     * @return array<string,string>
     */
    function recipient_tz_tld_map(): array
    {
        return [
            // German-speaking
            'de' => 'Europe/Berlin',
            'at' => 'Europe/Vienna',
            'ch' => 'Europe/Zurich',
            'li' => 'Europe/Zurich',
            // Rest of Europe
            'fr' => 'Europe/Paris',
            'be' => 'Europe/Brussels',
            'nl' => 'Europe/Amsterdam',
            'lu' => 'Europe/Luxembourg',
            'it' => 'Europe/Rome',
            'es' => 'Europe/Madrid',
            'pt' => 'Europe/Lisbon',
            'uk' => 'Europe/London',
            'gb' => 'Europe/London',
            'ie' => 'Europe/Dublin',
            'dk' => 'Europe/Copenhagen',
            'se' => 'Europe/Stockholm',
            'no' => 'Europe/Oslo',
            'fi' => 'Europe/Helsinki',
            'pl' => 'Europe/Warsaw',
            'cz' => 'Europe/Prague',
            'sk' => 'Europe/Bratislava',
            'hu' => 'Europe/Budapest',
            'gr' => 'Europe/Athens',
            'ro' => 'Europe/Bucharest',
            'bg' => 'Europe/Sofia',
            // Americas
            'us' => 'America/New_York',
            'ca' => 'America/Toronto',
            'mx' => 'America/Mexico_City',
            'br' => 'America/Sao_Paulo',
            'ar' => 'America/Argentina/Buenos_Aires',
            'cl' => 'America/Santiago',
            // Asia / Pacific
            'jp' => 'Asia/Tokyo',
            'kr' => 'Asia/Seoul',
            'cn' => 'Asia/Shanghai',
            'hk' => 'Asia/Hong_Kong',
            'tw' => 'Asia/Taipei',
            'sg' => 'Asia/Singapore',
            'in' => 'Asia/Kolkata',
            'ae' => 'Asia/Dubai',
            'il' => 'Asia/Jerusalem',
            'au' => 'Australia/Sydney',
            'nz' => 'Pacific/Auckland',
            // Africa
            'za' => 'Africa/Johannesburg',
        ];
    }
}

if (!function_exists('recipient_tz_from_email')) {
    /**
     * Pull the ccTLD off the email address and look it up in the map. Falls
     * back to $defaultTz for generic TLDs (.com, .org, .net, …) or
     * malformed addresses.
     */
    function recipient_tz_from_email(string $email, string $defaultTz): string
    {
        if ($defaultTz === '') {
            $defaultTz = 'UTC';
        }
        if (strpos($email, '@') === false) {
            return $defaultTz;
        }
        $domain = strtolower(trim(substr($email, strrpos($email, '@') + 1)));
        if ($domain === '') {
            return $defaultTz;
        }
        $parts = explode('.', $domain);
        $tld = end($parts);
        if (!is_string($tld) || $tld === '') {
            return $defaultTz;
        }
        $map = recipient_tz_tld_map();
        return $map[$tld] ?? $defaultTz;
    }
}

if (!function_exists('recipient_tz_clamp_hour')) {
    /**
     * @param mixed $raw
     */
    function recipient_tz_clamp_hour($raw, int $default = 9): int
    {
        if (!is_numeric($raw)) {
            return $default;
        }
        $v = (int) $raw;
        if ($v < 0) return 0;
        if ($v > 23) return 23;
        return $v;
    }
}

if (!function_exists('recipient_tz_clamp_window')) {
    /**
     * @param mixed $raw
     */
    function recipient_tz_clamp_window($raw, int $default = 4): int
    {
        if (!is_numeric($raw)) {
            return $default;
        }
        $v = (int) $raw;
        if ($v < 1) return 1;
        if ($v > 12) return 12;
        return $v;
    }
}

if (!function_exists('recipient_local_hour_at')) {
    /**
     * Compute the local hour-of-day for a recipient at the given UTC
     * instant. Pure (driven by the operator-supplied $nowUtc), so tests
     * don't have to freeze time globally.
     */
    function recipient_local_hour_at(string $email, string $defaultTz, int $nowUtc): int
    {
        $tz = recipient_tz_from_email($email, $defaultTz);
        try {
            $dt = (new \DateTimeImmutable('@' . $nowUtc))->setTimezone(new \DateTimeZone($tz));
            return (int) $dt->format('G');
        } catch (\Throwable $e) {
            // Bad zone string — fall back to UTC hour. Operator's typo
            // shouldn't silently send everyone at the wrong time.
            return (int) gmdate('G', $nowUtc);
        }
    }
}

if (!function_exists('recipient_in_send_window')) {
    /**
     * True iff $nowUtc maps to a local hour in [$targetHour, $targetHour +
     * $windowHours) for $email. Wraps over midnight: a target of 22 with
     * a 4h window matches 22, 23, 0, 1 local.
     */
    function recipient_in_send_window(
        string $email,
        string $defaultTz,
        int $targetHour,
        int $windowHours,
        int $nowUtc
    ): bool {
        $targetHour = max(0, min(23, $targetHour));
        $windowHours = max(1, min(24, $windowHours));
        $localHour = recipient_local_hour_at($email, $defaultTz, $nowUtc);
        for ($i = 0; $i < $windowHours; $i++) {
            $h = ($targetHour + $i) % 24;
            if ($localHour === $h) {
                return true;
            }
        }
        return false;
    }
}
