<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Shared DataTables server-side response contract. The load-bearing rule: the
 * "Next" button is gated by recordsFiltered, so recordsFiltered must be a REAL
 * filtered COUNT — never sizeof() of the already-LIMIT-sliced page of rows
 * (the bug in quick_tracker / tracker_report / web_mail_campaign / mail_campaign).
 */
final class DataTablesHelperTest extends TestCase
{
    public function testEnvelopeUsesFilteredCountNotRowCount(): void
    {
        // 100 total, 57 match the search, but only this 20-row page was fetched.
        $rows = array_fill(0, 20, ['x' => 1]);
        $env = taphish_dt_envelope(3, 100, 57, $rows);
        self::assertSame(3, $env['draw']);
        self::assertSame(100, $env['recordsTotal']);
        self::assertSame(57, $env['recordsFiltered']);   // NOT 20 — this is what gates Next
        self::assertCount(20, $env['data']);
    }

    public function testSearchClauseBuildsOrLikeWithBinds(): void
    {
        $r = taphish_dt_search_clause(['name', 'email'], 'foo');
        self::assertSame('(name LIKE ? OR email LIKE ?)', $r['sql']);
        self::assertSame(['%foo%', '%foo%'], $r['binds']);
    }

    public function testSearchClauseEmptyWhenNoTermOrNoCols(): void
    {
        self::assertSame(['sql' => '', 'binds' => []], taphish_dt_search_clause(['a'], '   '));
        self::assertSame(['sql' => '', 'binds' => []], taphish_dt_search_clause([], 'foo'));
    }

    public function testOrderClauseWhitelistsColumn(): void
    {
        self::assertSame('ORDER BY date DESC', taphish_dt_order_clause(['name', 'date'], 'date', 'desc'));
        self::assertSame('ORDER BY name ASC', taphish_dt_order_clause(['name', 'date'], 'name', 'asc'));
        // non-whitelisted / injection attempt → no ORDER BY (safe default)
        self::assertSame('', taphish_dt_order_clause(['name'], 'date; DROP TABLE x', 'asc'));
        self::assertSame('', taphish_dt_order_clause(['name'], 'evil', 'asc'));
    }

    public function testLimitClamp(): void
    {
        self::assertSame([0, 20], taphish_dt_limit(0, 20));
        self::assertSame([40, 50], taphish_dt_limit(40, 50));
        self::assertSame([0, -1], taphish_dt_limit(-5, -1));   // -1 length = "All" passes through; start clamped to 0
    }
}
