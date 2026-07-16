<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * P1.4a — the engagement picker must offer list-level Open + Delete for every
 * engagement, drafts included. Before this, a draft only got "Continue setup"
 * (→ the wizard), so it could never reach the detail view's delete button —
 * the operator-reported "can't delete engagements" bug. Behaviour is verified
 * by the live demo; these are the regression guards.
 */
final class EngagementPickerTest extends TestCase
{
    private function js(): string
    {
        return file_get_contents(dirname(__DIR__) . '/spear/js/engagement_view.js');
    }

    public function testPickerHasListLevelDelete(): void
    {
        self::assertStringContainsString(
            'deleteEngagementFromPicker',
            $this->js(),
            'Picker must expose a list-level delete for engagements'
        );
    }

    public function testPickerDeleteUsesUnlinkingServerAction(): void
    {
        // Deletion must go through delete_engagement, which UNLINKS linked
        // campaigns (sets engagement_id=NULL) rather than destroying them.
        self::assertMatchesRegularExpression(
            '/deleteEngagementFromPicker[\s\S]{0,500}delete_engagement/',
            $this->js(),
            'Picker delete must call the campaign-preserving delete_engagement action'
        );
    }

    public function testPickerReloadsAfterDelete(): void
    {
        self::assertMatchesRegularExpression(
            '/deleteEngagementFromPicker[\s\S]{0,600}loadPicker\(\)/',
            $this->js(),
            'Picker must refresh the list after a successful delete'
        );
    }

    public function testUnscopedBucketHasPerTypeDelete(): void
    {
        // Polish: per-row Löschen dispatches to each type's existing delete
        // action (which cleans up the item's data). The bucket only lists
        // engagement_id IS NULL items, so live scoped campaigns never appear.
        $js = $this->js();
        self::assertStringContainsString('deleteUnscopedItem', $js, 'bucket must offer a delete');
        self::assertStringContainsString('delete_campaign_from_campaign_id', $js, 'mail delete dispatch');
        self::assertStringContainsString('delete_web_tracker', $js, 'web delete dispatch');
        self::assertStringContainsString('delete_quick_tracker', $js, 'quick delete dispatch');
    }

    public function testUnscopedBucketIsWiredToAssign(): void
    {
        // P1.4b: the picker view must render the Unscoped bucket and its
        // "Zuordnen" must go through the assign_engagement action.
        $js = $this->js();
        self::assertStringContainsString('loadUnscoped', $js, 'Picker view must load the unscoped bucket');
        self::assertMatchesRegularExpression(
            '/assignItem[\s\S]{0,400}assign_engagement/',
            $js,
            'Assign must call the assign_engagement action'
        );
        self::assertStringContainsString(
            'eng_unscoped_table',
            file_get_contents(dirname(__DIR__) . '/spear/EngagementView.php'),
            'EngagementView must contain the unscoped bucket table'
        );
    }
}
