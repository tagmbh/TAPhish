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

    public function testEverySeedCtaUsesTheOperatorEditMarker(): void
    {
        // Design contract — Bug-3 root-cause fix (2026-06-09).
        //
        // Each seed body must use the literal operator-edit marker
        // `https://example.com/REPLACE-WITH-LANDING-URL` for its CTA, NOT
        // the `{{TRACKINGURL}}` merge token. Reason: `{{TRACKINGURL}}`
        // expands to the OPEN-PIXEL endpoint `/tmail?mid=…&rid=…`. If an
        // `<a href="{{TRACKINGURL}}">…</a>` slips into a real campaign,
        // every recipient clicks the CTA and lands on a 1×1 transparent
        // image — i.e. a blank white page (the "first two work, others
        // don't" production report on 2026-06-09).
        //
        // The marker is intentionally visible so the operator sees it
        // before launch, and the pre-send guard
        // `taphish_mail_body_is_unsafe_to_send()` refuses to dispatch any
        // mail whose body still carries it.
        foreach (taphish_pretext_seeds() as $s) {
            self::assertStringContainsString(
                'REPLACE-WITH-LANDING-URL',
                $s['body'],
                "Seed '{$s['name']}' must carry the operator-edit marker URL so an unedited launch surfaces the missing landing URL."
            );
            // The {{TRACKINGURL}} token expands to the open-tracking pixel
            // and is NEVER a valid CTA. A future edit must not regress.
            self::assertDoesNotMatchRegularExpression(
                '#<a\b[^>]*href\s*=\s*["\']{{TRACKINGURL}}["\']#i',
                $s['body'],
                "Seed '{$s['name']}' uses {{TRACKINGURL}} as a CTA href — that expands to the open-pixel and produces a blank white page."
            );
        }
    }

    public function testPretextCloneContentTypeIsTextHtml(): void
    {
        // shootMail() in common_functions.php sends HTML only when
        // mail_content_type === 'text/html' (full MIME string). The pretext
        // clone wrote 'html' (short form), which fell through to text() and
        // delivered the body as raw HTML markup. Regression: 2026-06-08.
        self::assertSame('text/html', taphish_pretext_clone_content_type());
    }

    // Phase 3.43c: ranking by preferred category.

    public function testRankForCategoriesFloatsPreferred(): void
    {
        $list = [
            ['id' => 1, 'category' => 'Shipping',       'name' => 'FedEx',  'subject' => '', 'body' => ''],
            ['id' => 2, 'category' => 'Authentication', 'name' => 'M365',   'subject' => '', 'body' => ''],
            ['id' => 3, 'category' => 'IT',             'name' => 'Reset',  'subject' => '', 'body' => ''],
        ];
        $ranked = taphish_pretext_rank_for_categories($list, ['Authentication', 'IT']);
        self::assertSame(2, $ranked[0]['id']);
        self::assertSame(3, $ranked[1]['id']);
        self::assertSame(1, $ranked[2]['id']);
    }

    public function testRankForCategoriesIsStableAlphaWithinTier(): void
    {
        $list = [
            ['id' => 1, 'category' => 'Authentication', 'name' => 'M365',   'subject' => '', 'body' => ''],
            ['id' => 2, 'category' => 'Authentication', 'name' => 'Google', 'subject' => '', 'body' => ''],
        ];
        $ranked = taphish_pretext_rank_for_categories($list, ['Authentication']);
        self::assertSame('Google', $ranked[0]['name']);
        self::assertSame('M365',   $ranked[1]['name']);
    }

    public function testRankForCategoriesIgnoresUnknownPreferences(): void
    {
        $list = [
            ['id' => 1, 'category' => 'Esoteric', 'name' => 'A', 'subject' => '', 'body' => ''],
        ];
        $ranked = taphish_pretext_rank_for_categories($list, ['Authentication']);
        self::assertSame(1, $ranked[0]['id']);
    }
}
