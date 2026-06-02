<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Pure-helper tests for Phase 3.39 pretext library.
 *
 * The schema migration + seed loader + clone helpers all need mysqli,
 * which we don't bring into the unit suite. taphish_pretext_seeds() is
 * pure data and is the right thing to exercise here: it lets us pin
 * the seed library shape so a future edit can't silently break the
 * gallery contract (uniqueness, category presence, required fields).
 */
final class PretextLibraryTest extends TestCase
{
    public function testReturnsAtLeastTwelveSeeds(): void
    {
        self::assertGreaterThanOrEqual(12, count(taphish_pretext_seeds()));
    }

    public function testEachSeedHasRequiredFields(): void
    {
        foreach (taphish_pretext_seeds() as $i => $s) {
            self::assertArrayHasKey('category', $s, "seed #$i missing category");
            self::assertArrayHasKey('name',     $s, "seed #$i missing name");
            self::assertArrayHasKey('subject',  $s, "seed #$i missing subject");
            self::assertArrayHasKey('body',     $s, "seed #$i missing body");
            self::assertArrayHasKey('tags',     $s, "seed #$i missing tags");
            self::assertNotSame('', $s['category']);
            self::assertNotSame('', $s['name']);
            self::assertNotSame('', $s['subject']);
            self::assertNotSame('', $s['body']);
        }
    }

    public function testCategoryNameUniquenessHolds(): void
    {
        $seen = [];
        foreach (taphish_pretext_seeds() as $s) {
            $key = $s['category'] . '||' . $s['name'];
            self::assertArrayNotHasKey(
                $key,
                $seen,
                "Duplicate seed (category, name): {$s['category']} / {$s['name']}"
            );
            $seen[$key] = true;
        }
    }

    public function testCoversAtLeastFiveCategories(): void
    {
        $cats = array_unique(array_column(taphish_pretext_seeds(), 'category'));
        self::assertGreaterThanOrEqual(5, count($cats));
    }

    public function testKeyRedTeamCategoriesPresent(): void
    {
        $cats = array_unique(array_column(taphish_pretext_seeds(), 'category'));
        foreach (['Authentication', 'Finance', 'HR', 'IT', 'Shipping'] as $required) {
            self::assertContains(
                $required,
                $cats,
                "Seed library is missing required category: $required"
            );
        }
    }

    public function testEverySeedUsesAtLeastOneMergeToken(): void
    {
        // Every starter should make use of at least one of the cron's
        // substitution tokens. A static "Dear sir/madam" body would
        // signal a low-quality seed that the operator will rewrite
        // entirely.
        $tokens = ['{{FNAME}}', '{{NAME}}', '{{EMAIL}}', '{{MDOMAIN}}', '{{RID}}'];
        foreach (taphish_pretext_seeds() as $s) {
            $blob = $s['subject'] . ' ' . $s['body'];
            $hit = false;
            foreach ($tokens as $t) {
                if (str_contains($blob, $t)) { $hit = true; break; }
            }
            self::assertTrue(
                $hit,
                "Seed '{$s['name']}' uses no merge token — would render identically for every recipient."
            );
        }
    }

    public function testEverySeedReservesATrackerSlot(): void
    {
        // Each body must include the REPLACE-WITH-TRACKER-URL marker so
        // the operator knows exactly where to drop the campaign URL.
        foreach (taphish_pretext_seeds() as $s) {
            self::assertStringContainsString(
                'REPLACE-WITH-TRACKER-URL',
                $s['body'],
                "Seed '{$s['name']}' is missing the tracker-URL placeholder."
            );
        }
    }
}
