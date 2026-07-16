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
}
