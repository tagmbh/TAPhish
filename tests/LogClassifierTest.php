<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class LogClassifierTest extends TestCase
{
    public function testAuthLogin(): void
    {
        $r = taphish_classify_log_entry('Account login');
        self::assertSame(['kind' => 'AUTH', 'severity' => 'ok'], $r);
    }

    public function testAuthLogout(): void
    {
        $r = taphish_classify_log_entry('Account logout');
        self::assertSame(['kind' => 'AUTH', 'severity' => 'ok'], $r);
    }

    public function test2faEnabled(): void
    {
        $r = taphish_classify_log_entry('2FA enabled for admin');
        self::assertSame(['kind' => 'AUTH', 'severity' => 'ok'], $r);
    }

    public function test2faDisabled(): void
    {
        $r = taphish_classify_log_entry('2FA disabled for admin');
        self::assertSame(['kind' => 'AUTH', 'severity' => 'warn'], $r);
    }

    public function testFailedLogin(): void
    {
        $r = taphish_classify_log_entry('Failed login attempt for admin');
        self::assertSame(['kind' => 'AUTH', 'severity' => 'warn'], $r);
    }

    public function testCampaignSent(): void
    {
        $r = taphish_classify_log_entry('Campaign sent: Q2-finance-test');
        self::assertSame(['kind' => 'CAMP', 'severity' => 'ok'], $r);
    }

    public function testCampaignCreated(): void
    {
        $r = taphish_classify_log_entry('Campaign created Q3-it');
        self::assertSame(['kind' => 'CAMP', 'severity' => 'ok'], $r);
    }

    public function testRecipientImported(): void
    {
        $r = taphish_classify_log_entry('Recipient list imported: finance-EU (89 rows)');
        self::assertSame(['kind' => 'RECP', 'severity' => 'ok'], $r);
    }

    public function testRecipientDeleted(): void
    {
        $r = taphish_classify_log_entry('Recipient list deleted: finance-EU');
        self::assertSame(['kind' => 'RECP', 'severity' => 'warn'], $r);
    }

    public function testMailSenderError(): void
    {
        $r = taphish_classify_log_entry('Mail sender error: connect timeout');
        self::assertSame(['kind' => 'SEND', 'severity' => 'error'], $r);
    }

    public function testTemplateCreated(): void
    {
        $r = taphish_classify_log_entry('Template created Office365-reset');
        self::assertSame(['kind' => 'TMPL', 'severity' => 'ok'], $r);
    }

    public function testUnknownEntryFallsBackToSys(): void
    {
        $r = taphish_classify_log_entry('Something nobody planned for');
        self::assertSame(['kind' => 'SYS', 'severity' => 'ok'], $r);
    }

    public function testEmptyStringIsSysOk(): void
    {
        $r = taphish_classify_log_entry('');
        self::assertSame(['kind' => 'SYS', 'severity' => 'ok'], $r);
    }

    // Phase 3.35: new lifecycle verbs.

    public function testCampaignUpdated(): void
    {
        $r = taphish_classify_log_entry('Campaign updated: Q3-it');
        self::assertSame(['kind' => 'CAMP', 'severity' => 'ok'], $r);
    }

    public function testCampaignDeleted(): void
    {
        $r = taphish_classify_log_entry('Campaign deleted: Q3-it');
        self::assertSame(['kind' => 'CAMP', 'severity' => 'warn'], $r);
    }

    public function testCampaignCopied(): void
    {
        $r = taphish_classify_log_entry('Campaign copied: Q3-it-clone');
        self::assertSame(['kind' => 'CAMP', 'severity' => 'ok'], $r);
    }

    public function testRecipientListCreated(): void
    {
        $r = taphish_classify_log_entry('Recipient list created: finance-EU');
        self::assertSame(['kind' => 'RECP', 'severity' => 'ok'], $r);
    }

    public function testRecipientListCopied(): void
    {
        $r = taphish_classify_log_entry('Recipient list copied: finance-EU-clone');
        self::assertSame(['kind' => 'RECP', 'severity' => 'ok'], $r);
    }

    public function testTemplateUpdated(): void
    {
        $r = taphish_classify_log_entry('Template updated: Office365-reset');
        self::assertSame(['kind' => 'TMPL', 'severity' => 'ok'], $r);
    }

    public function testTemplateDeleted(): void
    {
        $r = taphish_classify_log_entry('Template deleted: Office365-reset');
        self::assertSame(['kind' => 'TMPL', 'severity' => 'warn'], $r);
    }

    public function testTemplateCopied(): void
    {
        $r = taphish_classify_log_entry('Template copied: Office365-reset-clone');
        self::assertSame(['kind' => 'TMPL', 'severity' => 'ok'], $r);
    }

    // Phase 3.40: scanner hits surface in the activity feed as SCAN/warn.

    public function testScannerHitOnQuickTrackerIsScanWarn(): void
    {
        $r = taphish_classify_log_entry('Scanner hit on quick tracker QT123 (UA contains "safelinks")');
        self::assertSame(['kind' => 'SCAN', 'severity' => 'warn'], $r);
    }

    public function testScannerHitOnPixelIsScanWarn(): void
    {
        $r = taphish_classify_log_entry('Scanner hit on mail-open pixel for campaign C42 (PTR contains "amazonaws")');
        self::assertSame(['kind' => 'SCAN', 'severity' => 'warn'], $r);
    }

    // Phase 3.42: first capture per recipient surfaces as CAPT/ok.

    public function testFirstCaptureIsCaptOk(): void
    {
        $r = taphish_classify_log_entry('Capture: first submit on tracker XYZ123 [+2FA]');
        self::assertSame(['kind' => 'CAPT', 'severity' => 'ok'], $r);
    }

    // Phase 3.43a: engagement metadata creation surfaces as ENGM/ok.

    public function testEngagementCreatedIsEngmOk(): void
    {
        $r = taphish_classify_log_entry('Engagement created: Acme Q3');
        self::assertSame(['kind' => 'ENGM', 'severity' => 'ok'], $r);
    }

    public function testSiteClonedIsClonOk(): void
    {
        $r = taphish_classify_log_entry('Site cloned: acme-login from https://target.example');
        self::assertSame(['kind' => 'CLON', 'severity' => 'ok'], $r);
    }
}
