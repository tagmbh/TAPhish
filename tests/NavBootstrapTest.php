<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Structural guard for the sidebar navigation bootstrap.
 *
 * Every manager page that renders the shared sidebar (i.e. includes z_menu.php)
 * MUST also load the nav bootstrap (js/libs/sidebarmenu.js) — directly, or via
 * the shared z_navboot.php partial. Without it the sidebar's active-state and
 * submenu-expand wiring never runs, which is the operator-reported "you have to
 * click Home first before the left nav works" bug on EngagementView /
 * PretextLibrary / SenderToolkit / ToolsetChecker / QuickStart.
 *
 * Standalone pages that intentionally have no sidebar (e.g. ChangePwd.php) do
 * not include z_menu.php and are correctly ignored.
 */
final class NavBootstrapTest extends TestCase
{
    public function testEveryPageWithSidebarLoadsNavBootstrap(): void
    {
        $spear = dirname(__DIR__) . '/spear';
        $offenders = [];
        foreach (glob($spear . '/*.php') as $file) {
            $src = file_get_contents($file);
            if (strpos($src, 'z_menu.php') === false) {
                continue; // no sidebar on this page → nav bootstrap not required
            }
            $hasBoot = strpos($src, 'sidebarmenu.js') !== false
                    || strpos($src, 'z_navboot') !== false;
            if (!$hasBoot) {
                $offenders[] = basename($file);
            }
        }
        self::assertSame(
            [],
            $offenders,
            'Pages render the sidebar (z_menu.php) but never load its nav bootstrap: '
                . implode(', ', $offenders)
        );
    }
}
