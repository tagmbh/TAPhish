<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Structural guards for the QuickStart "Continue setup" resume pre-fill.
 *
 * Bug: clicking "Continue setup" on a draft opened the wizard with a BLANK
 * Step 1 — the engagement's own name/org/window/scope/notes were dropped.
 * The pure payload (meta) is covered by WizardStateTest; these lock the view
 * wiring that carries it to the form so the fix can't silently regress.
 */
final class QuickStartResumePrefillTest extends TestCase
{
    private function f(string $rel): string
    {
        return file_get_contents(dirname(__DIR__) . '/spear/' . $rel);
    }

    public function testPageEmitsResumeMetaHiddenInput(): void
    {
        $page = $this->f('QuickStart.php');
        self::assertStringContainsString('wizard_resume_meta', $page, 'hidden meta input for Step 1 pre-fill');
        self::assertStringContainsString("\$resume['meta']", $page, 'serialises the payload meta into the page');
    }

    public function testStepflowHydratesStep1FromMeta(): void
    {
        $js = $this->f('js/wizard_stepflow.js');
        self::assertStringContainsString('applyStep1Meta', $js, 'has the Step 1 hydration function');
        self::assertStringContainsString('wizard_resume_meta', $js, 'reads the meta hidden input');
        self::assertStringContainsString('utcToLocalInput', $js, 'converts stored UTC window to local');
        // The hydration must actually run in the resume path.
        self::assertMatchesRegularExpression(
            '/function restore\(\)\s*\{.*applyStep1Meta\(\).*\}/s',
            $js,
            'restore() invokes applyStep1Meta()'
        );
    }

    public function testHydrationTargetsEveryStep1Field(): void
    {
        $js = $this->f('js/wizard_stepflow.js');
        foreach (['eng_name', 'eng_org', 'eng_notes', 'eng_scope', 'eng_start', 'eng_end'] as $field) {
            self::assertStringContainsString($field, $js, "hydrates #$field");
        }
        // Setting scope must re-fire the chip renderer bound to the 'input' event.
        self::assertStringContainsString("trigger('input')", $js, 're-renders scope chips after fill');
    }
}
