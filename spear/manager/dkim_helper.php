<?php
/**
 * Phase 3.45c (Theme A Step 4): DKIM key-pair generation + DNS record
 * formatting for the Quick-Start Wizard sender setup.
 *
 * Pure on the formatting + validation; the actual `openssl_pkey_new`
 * call is a `?callable` seam so the unit suite can pin a deterministic
 * key fixture instead of generating a fresh 2048-bit RSA key per run
 * (which on Hostpoint Shared can take 10+ seconds and might be
 * disabled at the policy level).
 *
 * The operator publishes three TXT records — DKIM (per selector), SPF,
 * DMARC — at the look-alike domain registrar; the wizard renders all
 * three side-by-side with copy buttons so it's a one-paste-per-record
 * operation.
 */

if (!function_exists('taphish_dkim_validate_selector')) {
    /**
     * RFC 6376 selector: dot-separated labels, each label
     * [a-z0-9][a-z0-9-]{0,61}[a-z0-9]. We cap at 63 chars total which
     * is generous for any real selector (`s1`, `tap-2026`, etc.).
     */
    function taphish_dkim_validate_selector(string $selector): bool
    {
        $s = strtolower(trim($selector));
        if ($s === '' || strlen($s) > 63) {
            return false;
        }
        return (bool) preg_match('/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/', $s);
    }
}

if (!function_exists('taphish_dkim_extract_pubkey_b64')) {
    /**
     * Pull the base-64 body out of a PEM-formatted public key. Strips
     * the BEGIN/END boundaries + all whitespace. The DNS TXT record
     * publishes this as the `p=` value.
     */
    function taphish_dkim_extract_pubkey_b64(string $pem): string
    {
        $clean = preg_replace('/-----BEGIN[^-]+-----|-----END[^-]+-----/', '', $pem);
        if ($clean === null) {
            return '';
        }
        return preg_replace('/\s+/', '', $clean) ?? '';
    }
}

if (!function_exists('taphish_dkim_format_txt_record')) {
    /**
     * Build the `v=DKIM1; k=rsa; p=...` TXT record string the operator
     * pastes at `<selector>._domainkey.<domain>`.
     */
    function taphish_dkim_format_txt_record(string $pubkey_b64): string
    {
        return 'v=DKIM1; k=rsa; p=' . $pubkey_b64;
    }
}

if (!function_exists('taphish_dkim_suggested_spf_record')) {
    /**
     * Conservative SPF for an outbound-only look-alike — uses
     * ip4/ip6 placeholders so the operator knows to drop in the
     * actual sender IP. We don't auto-include third-party providers;
     * intentional choice so the operator confronts the explicit
     * authorisation step.
     */
    function taphish_dkim_suggested_spf_record(): string
    {
        return 'v=spf1 ip4:0.0.0.0/32 -all';
    }
}

if (!function_exists('taphish_dkim_suggested_dmarc_record')) {
    /**
     * DMARC monitoring policy for the look-alike; once the operator
     * sees clean traffic they can promote to quarantine/reject.
     */
    function taphish_dkim_suggested_dmarc_record(string $rua_mailto): string
    {
        $rua = trim($rua_mailto);
        $base = 'v=DMARC1; p=none; adkim=s; aspf=s';
        if ($rua !== '') {
            if (!preg_match('/^mailto:/i', $rua)) {
                $rua = 'mailto:' . $rua;
            }
            $base .= '; rua=' . $rua;
        }
        return $base;
    }
}

if (!function_exists('taphish_dkim_generate_keypair')) {
    /**
     * Generate a fresh 2048-bit RSA key pair, returning the private key
     * (PEM) + the DKIM-ready base64 public key + the formatted TXT
     * record string.
     *
     * Every openssl call is injectable so the suite can substitute a
     * deterministic fixture. The default callable list maps to the
     * built-in `openssl_*` family.
     *
     * @return array{
     *   ok: bool,
     *   private_key_pem?: string,
     *   public_key_b64?: string,
     *   txt_record?: string,
     *   error?: string
     * }
     */
    function taphish_dkim_generate_keypair(
        ?callable $pkey_new = null,
        ?callable $pkey_export = null,
        ?callable $pkey_details = null
    ): array {
        $pkey_new     = $pkey_new     ?? 'openssl_pkey_new';
        $pkey_export  = $pkey_export  ?? 'openssl_pkey_export';
        $pkey_details = $pkey_details ?? 'openssl_pkey_get_details';

        if (!function_exists('openssl_pkey_new') && $pkey_new === 'openssl_pkey_new') {
            return ['ok' => false, 'error' => 'openssl extension not available'];
        }

        $key = $pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if (!$key) {
            return ['ok' => false, 'error' => 'openssl_pkey_new failed (disabled on this host?)'];
        }

        $private = '';
        if (!$pkey_export($key, $private)) {
            return ['ok' => false, 'error' => 'openssl_pkey_export failed'];
        }

        $details = $pkey_details($key);
        if (!$details || empty($details['key'])) {
            return ['ok' => false, 'error' => 'openssl_pkey_get_details returned no public key'];
        }

        $pub_b64 = taphish_dkim_extract_pubkey_b64((string) $details['key']);
        if ($pub_b64 === '') {
            return ['ok' => false, 'error' => 'public key body was empty'];
        }

        return [
            'ok'              => true,
            'private_key_pem' => $private,
            'public_key_b64'  => $pub_b64,
            'txt_record'      => taphish_dkim_format_txt_record($pub_b64),
        ];
    }
}
