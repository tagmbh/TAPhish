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
}
