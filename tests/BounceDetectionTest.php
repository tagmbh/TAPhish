<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class BounceDetectionTest extends TestCase
{
    // --- bounce_is_envelope_likely_bounce ---------------------------------

    public function testEnvelopeMatchesCommonBouncePatterns(): void
    {
        $cases = [
            ['Undelivered Mail Returned to Sender', 'MAILER-DAEMON@example.com'],
            ['Delivery Status Notification (Failure)', 'postmaster@example.com'],
            ['Mail Delivery Failed: returning message to sender', 'Mail Delivery System <mailer-daemon@x>'],
            ['Returned mail: see transcript for details', 'noreply@x'],
            ['Auto reply: Out of office', 'user@x'],
            ['Permanent failure: 5.1.1', 'user@x'],
        ];
        foreach ($cases as [$subj, $from]) {
            self::assertTrue(
                bounce_is_envelope_likely_bounce($subj, $from),
                "Expected bounce match for: $subj / $from"
            );
        }
    }

    public function testEnvelopeRejectsLegitimateReplies(): void
    {
        $cases = [
            ['Re: Your benefits portal request', 'alice@example.com'],
            ['Thanks for the update', 'bob@example.com'],
            ['Fwd: meeting agenda', 'carol@example.com'],
        ];
        foreach ($cases as [$subj, $from]) {
            self::assertFalse(
                bounce_is_envelope_likely_bounce($subj, $from),
                "Should not be a bounce: $subj"
            );
        }
    }

    public function testEnvelopeMatchIsCaseInsensitive(): void
    {
        self::assertTrue(bounce_is_envelope_likely_bounce('UNDELIVERABLE MAIL', 'X'));
        self::assertTrue(bounce_is_envelope_likely_bounce('mail delivery failed', 'x'));
    }

    public function testEnvelopeMatchesMailerDaemonInFromOnly(): void
    {
        self::assertTrue(bounce_is_envelope_likely_bounce('Hi', 'MAILER-DAEMON@example.com'));
    }

    // --- bounce_extract_rid -----------------------------------------------

    public function testExtractsRidFromTypicalBounceBody(): void
    {
        $body = "Final-Recipient: rfc822; alice@example.com\n"
            . "Original-Message-ID: <abc123@spmailer.generated>\n"
            . "Status: 5.1.1\n";
        self::assertSame('abc123', bounce_extract_rid($body));
    }

    public function testExtractsRidFromReferencesHeader(): void
    {
        $body = "References: <xyz789ab@spmailer.generated>\nSome more text";
        self::assertSame('xyz789ab', bounce_extract_rid($body));
    }

    public function testExtractRidReturnsNullWhenMarkerAbsent(): void
    {
        self::assertNull(bounce_extract_rid("Final-Recipient: rfc822; alice@example.com\nStatus: 5.1.1"));
    }

    public function testExtractRidRejectsMalformed(): void
    {
        // 33-char "RID" — exceeds the {1,32} guard
        $body = '<' . str_repeat('a', 33) . '@spmailer.generated>';
        self::assertNull(bounce_extract_rid($body));
    }

    public function testExtractRidIsCaseInsensitiveOnDomain(): void
    {
        $body = '<RID42@SPMAILER.GENERATED>';
        self::assertSame('RID42', bounce_extract_rid($body));
    }

    // --- bounce_extract_reason --------------------------------------------

    public function testReasonPrefersDiagnosticCode(): void
    {
        $body = "Final-Recipient: rfc822; alice@example.com\n"
            . "Status: 5.1.1\n"
            . "Action: failed\n"
            . "Diagnostic-Code: smtp; 550 5.1.1 No such mailbox\n";
        self::assertSame('smtp; 550 5.1.1 No such mailbox', bounce_extract_reason($body));
    }

    public function testReasonFallsBackToStatus(): void
    {
        $body = "Status: 5.7.1\nAction: failed\n";
        self::assertSame('SMTP status 5.7.1', bounce_extract_reason($body));
    }

    public function testReasonFallsBackToAction(): void
    {
        $body = "Action: delayed\n";
        self::assertSame('Action: delayed', bounce_extract_reason($body));
    }

    public function testReasonFallsBackTo5xxLine(): void
    {
        $body = "Bounce trace:\n550 5.1.1 user unknown";
        self::assertStringContainsString('550', bounce_extract_reason($body));
    }

    public function testReasonFallsBackToBodyWhenNothingMatches(): void
    {
        self::assertSame('Mailbox full', bounce_extract_reason('Mailbox full'));
    }

    public function testReasonNeverEmptyForEmptyInput(): void
    {
        self::assertSame('Bounced (no diagnostic available)', bounce_extract_reason(''));
        self::assertSame('Bounced (no diagnostic available)', bounce_extract_reason("\n\n"));
    }

    public function testReasonTruncatesLongInput(): void
    {
        $long = str_repeat('x', 500);
        $reason = bounce_extract_reason($long, 100);
        self::assertSame(100, strlen($reason));
        self::assertStringEndsWith('…', $reason);
    }

    // --- bounce_compose_send_error ----------------------------------------

    public function testComposeWrapsWithPrefix(): void
    {
        self::assertSame('Bounced: 550 mailbox full', bounce_compose_send_error('550 mailbox full'));
    }

    // --- bounce_poll_due (Phase 3.12 cron auto-poll) ---------------------

    public function testPollDueWhenNeverPolled(): void
    {
        self::assertTrue(bounce_poll_due(null, 3600, 1000000));
    }

    public function testPollDueWhenIntervalElapsed(): void
    {
        $now = 1700000000;
        self::assertTrue(bounce_poll_due($now - 3600, 3600, $now));
        self::assertTrue(bounce_poll_due($now - 7200, 3600, $now));
    }

    public function testPollNotDueWhenWithinInterval(): void
    {
        $now = 1700000000;
        self::assertFalse(bounce_poll_due($now - 100, 3600, $now));
        self::assertFalse(bounce_poll_due($now - 3599, 3600, $now));
    }

    public function testPollDisabledWhenIntervalZeroOrNegative(): void
    {
        self::assertFalse(bounce_poll_due(null, 0, 1000000));
        self::assertFalse(bounce_poll_due(null, -1, 1000000));
        self::assertFalse(bounce_poll_due(0, 0, 1000000));
    }
}
