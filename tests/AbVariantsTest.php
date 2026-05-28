<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class AbVariantsTest extends TestCase
{
    public function testReturnsAOrB(): void
    {
        foreach (['abc', 'def', 'rid_with_underscore', '0123456789'] as $rid) {
            $v = ab_assign_variant($rid);
            self::assertContains($v, ['A', 'B'], "RID $rid produced unexpected variant: $v");
        }
    }

    public function testAssignmentIsDeterministic(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $rid = 'stable_' . $i;
            self::assertSame(
                ab_assign_variant($rid),
                ab_assign_variant($rid),
                "Variant for $rid changed between calls"
            );
        }
    }

    public function testAssignmentRoughlyBalanced(): void
    {
        // 1000 sequential RIDs should split close to 50/50 — accept
        // anything within ±10% (i.e. 400..600) to leave headroom for
        // crc32's distribution edges.
        $rids = [];
        for ($i = 0; $i < 1000; $i++) {
            $rids[] = 'rid_' . $i;
        }
        $sum = ab_assignment_summary($rids);
        self::assertSame(1000, $sum['total']);
        self::assertGreaterThan(400, $sum['A']);
        self::assertLessThan(600, $sum['A']);
        self::assertSame(1000, $sum['A'] + $sum['B']);
    }

    public function testSummarySkipsNonStringEntries(): void
    {
        $sum = ab_assignment_summary(['rid_one', 123, null, 'rid_two', ['x']]);
        self::assertSame(2, $sum['total']);
        self::assertSame($sum['total'], $sum['A'] + $sum['B']);
    }

    public function testSummaryEmpty(): void
    {
        $sum = ab_assignment_summary([]);
        self::assertSame(['A' => 0, 'B' => 0, 'total' => 0], $sum);
    }

    public function testAssignmentStableAcrossCommonRidShapes(): void
    {
        // Capture a few known mappings so we notice if a refactor
        // changes the underlying hash. Pin specific rids that are
        // common in CTFs/demos.
        self::assertSame('A', ab_assign_variant('demo_rid_1'));
        self::assertSame('A', ab_assign_variant('demo_rid_3'));
        self::assertSame('B', ab_assign_variant('demo_rid_4'));
    }
}
