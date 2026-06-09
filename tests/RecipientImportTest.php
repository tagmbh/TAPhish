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
        // CHANGED (auto-detection): the BOM is still stripped and the
        // "Email" header line is still recognised + dropped. But unlike
        // the old fixed-column parser, a single-column Email file is now
        // valid: the lone email cell is detected via FILTER_VALIDATE_EMAIL
        // and accepted as a row with empty fname/lname. We still assert
        // the BOM-strip works (otherwise the header cell would read
        // "\xEF\xBB\xBFEmail" — still header-like, but the point of this
        // test is the strip path stays correct end to end).
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(1, $p['rows']);
        self::assertCount(0, $p['errors']);
        self::assertSame('bob@acme.test', $p['rows'][0]['email']);
        self::assertSame('', $p['rows'][0]['fname']);
        self::assertSame('', $p['rows'][0]['lname']);
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

    public function testParseSemicolonDelimiter(): void
    {
        $csv = "First;Last;Email\nAlice;Smith;alice@acme.test\nBob;Jones;bob@acme.test\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(2, $p['rows']);
        self::assertSame('alice@acme.test', $p['rows'][0]['email']);
        self::assertSame('Alice', $p['rows'][0]['fname']);
        self::assertSame('Smith', $p['rows'][0]['lname']);
        self::assertCount(0, $p['errors']);
    }

    public function testParseTabDelimiter(): void
    {
        $csv = "First\tLast\tEmail\nAlice\tSmith\talice@acme.test\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(1, $p['rows']);
        self::assertSame('alice@acme.test', $p['rows'][0]['email']);
        self::assertSame('Alice', $p['rows'][0]['fname']);
        self::assertSame('Smith', $p['rows'][0]['lname']);
        self::assertCount(0, $p['errors']);
    }

    public function testParseEmailFirstColumnOrder(): void
    {
        // Email,First,Last — email is in cell 0, names follow.
        $csv = "Email,First,Last\nalice@acme.test,Alice,Smith\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(1, $p['rows']);
        self::assertSame('alice@acme.test', $p['rows'][0]['email']);
        self::assertSame('Alice', $p['rows'][0]['fname']);
        self::assertSame('Smith', $p['rows'][0]['lname']);
    }

    public function testParseNameEmailTwoColumns(): void
    {
        // "Name,Email" header: the generic name column maps to fname.
        $csv = "Name,Email\nAlice,alice@acme.test\nBob,bob@acme.test\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(2, $p['rows']);
        self::assertSame('alice@acme.test', $p['rows'][0]['email']);
        self::assertSame('Alice', $p['rows'][0]['fname']);
        self::assertSame('', $p['rows'][0]['lname']);
    }

    public function testParseNamedHeaderEmailFirstLast(): void
    {
        // Named header: email,first_name,last_name in an unusual order.
        $csv = "email,first_name,last_name\nalice@acme.test,Alice,Smith\nbob@acme.test,Bob,Jones\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(2, $p['rows']);
        self::assertSame('alice@acme.test', $p['rows'][0]['email']);
        self::assertSame('Alice', $p['rows'][0]['fname']);
        self::assertSame('Smith', $p['rows'][0]['lname']);
    }

    public function testParseSingleEmailColumnWithoutHeader(): void
    {
        // No header, just emails — each is accepted with empty names.
        $csv = "alice@acme.test\nbob@acme.test\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(2, $p['rows']);
        self::assertSame('alice@acme.test', $p['rows'][0]['email']);
        self::assertSame('', $p['rows'][0]['fname']);
        self::assertSame('', $p['rows'][0]['lname']);
        self::assertCount(0, $p['errors']);
    }

    public function testParseSingleCellFullNameSplit(): void
    {
        // "Alice Smith,alice@acme.test" — the lone name cell is split on
        // the first space into fname + lname.
        $csv = "Name,Email\nAlice Smith,alice@acme.test\nBob,bob@acme.test\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(2, $p['rows']);
        self::assertSame('Alice', $p['rows'][0]['fname']);
        self::assertSame('Smith', $p['rows'][0]['lname']);
        // Bob has no space → fname only.
        self::assertSame('Bob', $p['rows'][1]['fname']);
        self::assertSame('', $p['rows'][1]['lname']);
    }

    public function testParseQuotedFieldWithEmbeddedDelimiter(): void
    {
        // A quoted "Last, First" cell carries the delimiter inside the
        // enclosure, so it must stay one column and not spuriously look like
        // an extra field or split the email off. (F6 edge case.)
        $csv = "Name,Email\n\"Smith, Alice\",alice@acme.test\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(1, $p['rows']);
        self::assertCount(0, $p['errors']);
        self::assertSame('alice@acme.test', $p['rows'][0]['email']);
        // The single mapped name cell "Smith, Alice" is split on the first
        // space; the embedded comma stays attached to the first token (the
        // point of the test is that the quoted delimiter never split columns).
        self::assertSame('Alice', $p['rows'][0]['lname']);
    }

    public function testParseNonLatinNamedHeaderMapsViaSynonyms(): void
    {
        // German column names are recognised by the header synonym regexes
        // (vorname → fname, nachname → lname, e-mail → email). (F6 edge case.)
        $csv = "Vorname,Nachname,E-Mail\nAlice,Smith,alice@acme.test\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(1, $p['rows']);
        self::assertSame('alice@acme.test', $p['rows'][0]['email']);
        self::assertSame('Alice', $p['rows'][0]['fname']);
        self::assertSame('Smith', $p['rows'][0]['lname']);
    }

    public function testParseUnmappableNonLatinHeaderFallsBackToScan(): void
    {
        // A header whose names match no synonym (e.g. Japanese) is still
        // recognised as a header (no cell is an email) and dropped; the data
        // rows are then parsed by the email-scan path. Graceful degradation
        // rather than mis-reading the header as data. (F6 edge case.)
        $csv = "氏名,メール\nAlice,alice@acme.test\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(1, $p['rows']);
        self::assertSame('alice@acme.test', $p['rows'][0]['email']);
        self::assertSame('Alice', $p['rows'][0]['fname']);
    }

    public function testParseHeaderlessFirstRowWithNoEmailIsConsumedAsHeader(): void
    {
        // KNOWN LIMITATION (handoff §4.5 / review F6): the header heuristic is
        // "first non-blank line is a header when it mentions mail OR no cell is
        // a valid email". A genuine first DATA row that happens to carry no
        // email (e.g. a name-only row) is therefore swallowed as a header and
        // silently dropped — it is NOT recorded as a parse error. This test
        // pins that behaviour so a future change to the heuristic is noticed.
        $csv = "Alice,Smith\nbob@acme.test\n";
        $p = taphish_recipient_csv_parse($csv);
        self::assertCount(1, $p['rows']);                 // only bob survives
        self::assertSame('bob@acme.test', $p['rows'][0]['email']);
        self::assertCount(0, $p['errors']);               // the dropped row is silent
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
