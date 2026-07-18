<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * FEATURE-R2.4 Phase 1: structural guards for the deploy dispatcher wiring.
 * Engine behaviour is covered by LandingDeployTest; these lock the manager +
 * authz so the deploy actions can't be exposed unauthenticated/unauthorised.
 */
final class HostedPagesDeployWiringTest extends TestCase
{
    private function f(string $rel): string
    {
        return file_get_contents(dirname(__DIR__) . '/spear/' . $rel);
    }

    public function testManagerDispatchesTheThreeActions(): void
    {
        $m = $this->f('manager/hosted_pages_manager.php');
        self::assertStringContainsString('landing_deploy_targets', $m);
        self::assertStringContainsString("== 'landing_deploy'", $m, 'dispatches the deploy action');
        self::assertStringContainsString('landing_deploy_verify', $m);
        self::assertStringContainsString("/landing_deploy.php'", $m, 'loads the tested engine');
        self::assertStringContainsString('taphish_require_authorize_or_die', $m, 'default-deny authz gate');
        self::assertStringContainsString('csrf_require()', $m, 'CSRF-protected');
        self::assertStringContainsString('isSessionValid()', $m, 'auth-gated');
    }

    public function testManagerValidatesBothSourceAndTarget(): void
    {
        $m = $this->f('manager/hosted_pages_manager.php');
        self::assertStringContainsString('taphish_landing_deploy_resolve_source', $m, 'source path validated');
        self::assertStringContainsString('taphish_landing_deploy_run', $m, 'target validated inside run()');
    }

    public function testActionsAreOperatorTierGated(): void
    {
        $authz = $this->f('manager/authz.php');
        foreach (['landing_deploy', 'landing_deploy_targets', 'landing_deploy_verify'] as $a) {
            self::assertMatchesRegularExpression(
                "/'" . preg_quote($a, '/') . "'\\s*=>\\s*\\['super-admin', 'operator'\\]/",
                $authz,
                "$a must be gated super-admin/operator"
            );
        }
    }

    public function testDeployPageWired(): void
    {
        $page = $this->f('HostDeploy.php');
        foreach (['hd_source', 'hd_host', 'hd_deploy', 'hd_result'] as $id) {
            self::assertStringContainsString($id, $page, "page has #$id");
        }
        self::assertStringContainsString('host_deploy.js', $page, 'loads the client');
        self::assertStringContainsString('z_navboot.php', $page, 'nav bootstrap');
        self::assertStringContainsString('isSessionValid(true)', $page, 'auth-gated');
    }

    public function testDeployClientCallsTheActions(): void
    {
        $js = $this->f('js/host_deploy.js');
        self::assertStringContainsString('hosted_pages_manager', $js, 'posts to the manager');
        self::assertStringContainsString('landing_deploy_targets', $js, 'loads sources + targets');
        self::assertStringContainsString("'landing_deploy'", $js, 'issues the deploy');
        self::assertStringContainsString('X-CSRF-Token', $js, 'sends the CSRF token');
        self::assertStringContainsString('function esc', $js, 'escapes rendered output');
    }

    public function testNavEntryGatedOnDeployPermission(): void
    {
        $menu = $this->f('z_menu.php');
        self::assertStringContainsString('/spear/HostDeploy', $menu, 'nav links the deploy page');
        self::assertMatchesRegularExpression(
            "/nav_can\\('landing_deploy'\\).*HostDeploy/s",
            $menu,
            'nav entry gated on the landing_deploy permission'
        );
    }
}
