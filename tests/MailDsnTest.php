<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pure-helper tests for spear/manager/mail_dsn.php (taphish_mailer_dsn).
 *
 * Pins the per-provider DSN shape so a refactor / Symfony Mailer upgrade
 * can't silently break operator SMTP configs.
 */
final class MailDsnTest extends TestCase
{
    public function testCustomSmtpUsesProvidedHostAndCredentials(): void
    {
        $dsn = taphish_mailer_dsn('custom', 'info%40autodiscover.li', 'pw', 'asmtp.mail.hostpoint.ch:587', 0);
        self::assertSame(
            'smtp://info%40autodiscover.li:pw@asmtp.mail.hostpoint.ch:587?verify_peer=0',
            $dsn
        );
    }

    public function testUnknownProviderFallsBackToCustomSmtp(): void
    {
        // An unknown provider key MUST NOT drop to a managed DSN — it must
        // use the operator-supplied SMTP host as a custom relay.
        $dsn = taphish_mailer_dsn('not-a-provider', 'u', 'p', 'relay.example:25', 1);
        self::assertSame('smtp://u:p@relay.example:25?verify_peer=1', $dsn);
    }

    public function testProviderKeyIsCaseInsensitive(): void
    {
        $a = taphish_mailer_dsn('Gmail', 'u', 'p', 'unused', 0);
        $b = taphish_mailer_dsn('gmail', 'u', 'p', 'unused', 0);
        $c = taphish_mailer_dsn('GMAIL', 'u', 'p', 'unused', 0);
        self::assertSame($a, $b);
        self::assertSame($b, $c);
        self::assertStringStartsWith('gmail+smtp://', $a);
    }

    public function testVerifyPeerFlagAppearsOnEveryBranch(): void
    {
        $providers = ['amazon_ses', 'gmail', 'mailchimp_mandrill', 'mailgun', 'mailjet', 'postmark', 'sendgrid', 'sendinblue', 'mailpace', 'custom'];
        foreach ($providers as $p) {
            $dsn = taphish_mailer_dsn($p, 'u', 'p', 'h:1', 1);
            self::assertStringContainsString('verify_peer=1', $dsn, "verify_peer missing on $p");
            // and it's a query string, not embedded into the auth part
            self::assertStringContainsString('?verify_peer=', $dsn);
        }
    }

    public function testManagedProvidersDoNotLeakSmtpHost(): void
    {
        // For managed providers the smtp_server param is irrelevant — the
        // operator's "default" alias resolves via Symfony Mailer's transport
        // factory. Make sure the host doesn't bleed into the DSN.
        $hostMarker = 'BLEED-MARKER-HOST';
        foreach (['amazon_ses', 'gmail', 'mailchimp_mandrill', 'mailgun', 'mailjet', 'postmark', 'sendgrid', 'sendinblue', 'mailpace'] as $p) {
            $dsn = taphish_mailer_dsn($p, 'u', 'p', $hostMarker, 0);
            self::assertStringNotContainsString($hostMarker, $dsn, "managed provider $p leaked smtp_server into DSN");
            self::assertStringContainsString('@default', $dsn, "managed provider $p missing @default host");
        }
    }

    public function testPasswordOnlyProvidersOmitUsername(): void
    {
        // postmark, sendgrid, mailpace use a single token (the password param)
        // and MUST NOT emit "user:" in the DSN — that would shift the token
        // into the wrong position and break auth.
        foreach (['postmark' => 'postmark+smtp', 'sendgrid' => 'sendgrid+smtp', 'mailpace' => 'mailpace+api'] as $p => $scheme) {
            $dsn = taphish_mailer_dsn($p, 'IGNORE_THIS_USER', 'TOKEN', 'unused', 0);
            self::assertStringStartsWith($scheme . '://TOKEN@default', $dsn, "$p DSN shape wrong: $dsn");
            self::assertStringNotContainsString('IGNORE_THIS_USER', $dsn, "$p leaked username when it should have been ignored");
        }
    }

    public function testUserPassProvidersIncludeBothInAuth(): void
    {
        // amazon_ses, gmail, mandrill, mailgun, mailjet, sendinblue all use
        // user:pass@default. The compose order matters — user first, pass second.
        foreach (['amazon_ses' => 'ses+smtp', 'gmail' => 'gmail+smtp', 'mailchimp_mandrill' => 'mandrill+smtp', 'mailgun' => 'mailgun+smtp', 'mailjet' => 'mailjet+smtp', 'sendinblue' => 'sendinblue+smtp'] as $p => $scheme) {
            $dsn = taphish_mailer_dsn($p, 'USR', 'PWD', 'unused', 0);
            self::assertSame("$scheme://USR:PWD@default?verify_peer=0", $dsn, "$p DSN shape wrong");
        }
    }

    public function testPreservesAlreadyEncodedCredentials(): void
    {
        // Callers urlencode() the credentials BEFORE calling this composer. We
        // must not re-encode (would double-percent-encode `%40` into `%2540`).
        $dsn = taphish_mailer_dsn('custom', 'user%40dom', 'p%3Aass', 'h:1', 0);
        self::assertStringContainsString('user%40dom:p%3Aass@h:1', $dsn);
        // explicit: no double-encoding
        self::assertStringNotContainsString('%2540', $dsn);
        self::assertStringNotContainsString('%253A', $dsn);
    }

    public function testGetMailerDSNDelegatesAndStaysBackwardsCompatible(): void
    {
        // The pre-existing global getMailerDSN() in common_functions.php must
        // produce identical output for every provider so the refactor stays
        // a true no-op for all operator-configured senders.
        if (!function_exists('getMailerDSN')) {
            self::markTestSkipped('getMailerDSN() not loaded in this test run');
        }
        foreach (['amazon_ses', 'gmail', 'mailchimp_mandrill', 'mailgun', 'mailjet', 'postmark', 'sendgrid', 'sendinblue', 'mailpace', 'custom', 'totally-unknown'] as $p) {
            self::assertSame(
                taphish_mailer_dsn($p, 'u', 'p', 'h:1', 0),
                getMailerDSN($p, 'u', 'p', 'h:1', 0),
                "delegation drift on provider $p"
            );
        }
    }
}
