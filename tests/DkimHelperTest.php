<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.45c: pure-side tests for DKIM key-pair generation, selector
 * validation, and DNS TXT formatting. Every openssl call is injected
 * so the suite stays deterministic + offline + fast.
 */
final class DkimHelperTest extends TestCase
{
    public function testSelectorAcceptsSimpleLabel(): void
    {
        self::assertTrue(taphish_dkim_validate_selector('s1'));
        self::assertTrue(taphish_dkim_validate_selector('tap-2026'));
    }

    public function testSelectorAcceptsDottedLabels(): void
    {
        self::assertTrue(taphish_dkim_validate_selector('s1.mail'));
    }

    public function testSelectorRejectsLeadingOrTrailingHyphen(): void
    {
        self::assertFalse(taphish_dkim_validate_selector('-bad'));
        self::assertFalse(taphish_dkim_validate_selector('bad-'));
    }

    public function testSelectorRejectsEmpty(): void
    {
        self::assertFalse(taphish_dkim_validate_selector(''));
        self::assertFalse(taphish_dkim_validate_selector('   '));
    }

    public function testSelectorRejectsTooLong(): void
    {
        self::assertFalse(taphish_dkim_validate_selector(str_repeat('a', 64)));
    }

    public function testSelectorRejectsInvalidChars(): void
    {
        self::assertFalse(taphish_dkim_validate_selector('foo_bar'));
        self::assertFalse(taphish_dkim_validate_selector('foo bar'));
    }

    public function testExtractPubkeyB64StripsPemBoundaries(): void
    {
        $pem = "-----BEGIN PUBLIC KEY-----\nMIIBIj  ANB  gkqhkiG9w0BAQEFAAOCAQ8A\nMIIBCg==\n-----END PUBLIC KEY-----\n";
        self::assertSame('MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCg==', taphish_dkim_extract_pubkey_b64($pem));
    }

    public function testFormatTxtRecordIsDkimShape(): void
    {
        self::assertSame(
            'v=DKIM1; k=rsa; p=ABCDE',
            taphish_dkim_format_txt_record('ABCDE')
        );
    }

    public function testSuggestedSpfIsHardenedBaseline(): void
    {
        self::assertStringContainsString('v=spf1', taphish_dkim_suggested_spf_record());
        self::assertStringContainsString('-all', taphish_dkim_suggested_spf_record());
    }

    public function testSuggestedDmarcWithRua(): void
    {
        $r = taphish_dkim_suggested_dmarc_record('soc@acme.test');
        self::assertStringContainsString('v=DMARC1', $r);
        self::assertStringContainsString('p=none', $r);
        self::assertStringContainsString('rua=mailto:soc@acme.test', $r);
    }

    public function testSuggestedDmarcKeepsMailtoPrefix(): void
    {
        $r = taphish_dkim_suggested_dmarc_record('mailto:soc@acme.test');
        self::assertStringContainsString('rua=mailto:soc@acme.test', $r);
        self::assertStringNotContainsString('mailto:mailto:', $r);
    }

    public function testSuggestedDmarcOmitsRuaWhenBlank(): void
    {
        $r = taphish_dkim_suggested_dmarc_record('');
        self::assertStringContainsString('v=DMARC1', $r);
        self::assertStringNotContainsString('rua', $r);
    }

    public function testGenerateKeypairUsesInjectedOpenssl(): void
    {
        $pkey_new = function (array $opts) {
            self::assertSame(2048, $opts['private_key_bits']);
            return 'fake-key-handle';
        };
        $pkey_export = function ($key, &$out) {
            $out = "-----BEGIN PRIVATE KEY-----\nFAKEPRIVATE\n-----END PRIVATE KEY-----\n";
            return true;
        };
        $pkey_details = fn ($key) => [
            'key' => "-----BEGIN PUBLIC KEY-----\nFAKE PUBKEY\n-----END PUBLIC KEY-----\n",
        ];

        $r = taphish_dkim_generate_keypair($pkey_new, $pkey_export, $pkey_details);
        self::assertTrue($r['ok']);
        self::assertStringContainsString('FAKEPRIVATE', $r['private_key_pem']);
        self::assertSame('FAKEPUBKEY', $r['public_key_b64']);
        self::assertSame('v=DKIM1; k=rsa; p=FAKEPUBKEY', $r['txt_record']);
    }

    public function testGenerateKeypairReportsOpensslDisabled(): void
    {
        $pkey_new = fn () => false;
        $r = taphish_dkim_generate_keypair($pkey_new);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('openssl_pkey_new', $r['error']);
    }

    public function testGenerateKeypairReportsExportFailure(): void
    {
        $pkey_new = fn () => 'k';
        $pkey_export = fn ($k, &$o) => false;
        $r = taphish_dkim_generate_keypair($pkey_new, $pkey_export);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('export', $r['error']);
    }
}
