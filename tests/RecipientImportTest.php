<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.45c: pure-side tests for the recipient CSV import helpers.
 * No DB; the actual persistence path stays in the legacy uploadUserCVS
 * + the new wizard_recipient_commit dispatcher action.
 */
final class RecipientImportTest extends TestCase
{
    public function testParseAcceptsThreeColumnFnameLnameEmail(): void
    {
        $csv = "First,Last,Email\nAlice,Smith,alice@acme.test\nBob,Jones,bob@acme.test\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(2, $p['rows']);
        self::assertSame('alice@acme.test', $p['rows'][0]['email']);
        self::assertSame('Smith', $p['rows'][0]['lname']);
        self::assertCount(0, $p['errors']);
    }

    public function testParseFallsBackToCellOneEmailIfCellTwoNotEmail(): void
    {
        // Legacy "fname,email,notes" shape — Alice's email is in cell 1.
        $csv = "First,Email,Notes\nAlice,alice@acme.test,vip\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(1, $p['rows']);
        self::assertSame('alice@acme.test', $p['rows'][0]['email']);
    }

    public function testParseStripsUtf8Bom(): void
    {
        $csv = "\xEF\xBB\xBFEmail\nbob@acme.test\n";
        // BOM is stripped, the "Email" header is dropped, then the next
        // line has just an email in cell 0 → cell 2 is empty, falls
        // through to "invalid email format". This is the legacy
        // behaviour and we keep it deterministic.
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(0, $p['rows']);
        self::assertNotEmpty($p['errors']);
    }

    public function testParseHandlesCrlfAndCr(): void
    {
        $csv = "First,Last,Email\r\nAlice,Smith,alice@acme.test\rBob,Jones,bob@acme.test";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(2, $p['rows']);
    }

    public function testParseCollectsBadRowsInsteadOfDying(): void
    {
        $csv = "First,Last,Email\nGood,One,good@acme.test\nBroken,Row,not-an-email\nAnother,Good,other@acme.test\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(2, $p['rows']);
        self::assertCount(1, $p['errors']);
        self::assertSame('not-an-email', $p['errors'][0]['email']);
    }

    public function testParseHandlesEmptyAndBlankLines(): void
    {
        $csv = "First,Last,Email\n\nAlice,Smith,alice@acme.test\n   \nBob,Jones,bob@acme.test\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(2, $p['rows']);
    }

    public function testParseLowercasesEmail(): void
    {
        $csv = "First,Last,Email\nAlice,Smith,ALICE@Acme.Test\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertSame('alice@acme.test', $p['rows'][0]['email']);
    }

    public function testDomainBreakdownCountsPerDomain(): void
    {
        $rows = [
            ['email' => 'a@acme.test'],
            ['email' => 'b@acme.test'],
            ['email' => 'c@vendor.example'],
        ];
        $b = taphish_recipient_domain_breakdown($rows);
        self::assertSame(['acme.test' => 2, 'vendor.example' => 1], $b);
    }

    public function testDomainBreakdownEmptyOnNoRows(): void
    {
        self::assertSame([], taphish_recipient_domain_breakdown([]));
    }

    public function testAllowlistViolationsExactMatch(): void
    {
        $rows = [
            ['email' => 'a@acme.test'],
            ['email' => 'b@notallowed.example'],
        ];
        $v = taphish_recipient_allowlist_violations($rows, ['acme.test']);
        self::assertCount(1, $v);
        self::assertSame('notallowed.example', $v[0]['domain']);
    }

    public function testAllowlistViolationsCoversSubdomain(): void
    {
        $rows = [
            ['email' => 'a@hr.acme.test'],
            ['email' => 'b@payroll.acme.test'],
        ];
        $v = taphish_recipient_allowlist_violations($rows, ['acme.test']);
        self::assertCount(0, $v);
    }

    public function testAllowlistViolationsCaseInsensitive(): void
    {
        $rows = [['email' => 'X@ACME.TEST']];
        $v = taphish_recipient_allowlist_violations($rows, ['acme.test']);
        self::assertCount(0, $v);
    }

    public function testAllowlistViolationsEmptyListMeansNoEnforcement(): void
    {
        $rows = [['email' => 'random@anything.example']];
        self::assertSame([], taphish_recipient_allowlist_violations($rows, []));
    }

    public function testPreviewBundlesEverythingTogether(): void
    {
        $csv = "First,Last,Email\nA,One,a@acme.test\nB,Two,b@vendor.example\nbroken,row,nope\n";
        $r = taphish_recipient_preview($csv, ['acme.test']);
        self::assertSame(2, $r['row_count']);
        self::assertCount(1, $r['parse_errors']);
        self::assertCount(1, $r['scope_violations']);
        self::assertSame(2, count($r['domain_breakdown']));
    }
}
