<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.52 task 1: pure-helper tests for the BeEF integration.
 *
 * Covers the hook-snippet builder, REST auth-response parser, hook
 * list summarizer, scope validator, and the injectable-HTTP seam.
 * Live HTTP, DB, and the at-rest encryption round-trip live in the
 * integration tier; everything here runs offline.
 */
final class BeefIntegrationTest extends TestCase
{
    // ---- hook snippet ----------------------------------------------------

    public function testHookSnippetBuildsAsyncTag(): void
    {
        $s = beef_hook_snippet('http://10.0.0.5:3000');
        self::assertStringContainsString('async', $s);
        self::assertStringContainsString('http://10.0.0.5:3000/hook.js', $s);
        self::assertStringStartsWith('<script', $s);
    }

    public function testHookSnippetRespectsCustomHookFile(): void
    {
        $s = beef_hook_snippet('https://beef.ops.example', 'analytics.js');
        self::assertStringContainsString('https://beef.ops.example/analytics.js', $s);
    }

    public function testHookSnippetTrimsBaseUrlSlash(): void
    {
        $s = beef_hook_snippet('http://10.0.0.5:3000/', 'hook.js');
        self::assertStringContainsString('http://10.0.0.5:3000/hook.js', $s);
        self::assertStringNotContainsString('//hook.js', $s);
    }

    public function testHookSnippetRejectsJunkUrl(): void
    {
        self::assertSame('', beef_hook_snippet('javascript:alert(1)'));
        self::assertSame('', beef_hook_snippet(''));
        self::assertSame('', beef_hook_snippet('ftp://evil'));
        self::assertSame('', beef_hook_snippet('not-a-url'));
    }

    public function testHookSnippetEscapesHtmlInUrl(): void
    {
        $s = beef_hook_snippet('http://example.com/<x>');
        self::assertStringNotContainsString('<x>', $s);
        self::assertStringContainsString('&lt;x&gt;', $s);
    }

    // ---- REST auth parsing ----------------------------------------------

    public function testRestAuthParsesTokenFromBody(): void
    {
        $resp = json_encode(['success' => true, 'token' => 'tok_abc123']);
        self::assertSame('tok_abc123', beef_parse_auth_response($resp));
    }

    public function testRestAuthAcceptsArrayInput(): void
    {
        self::assertSame('tok_x', beef_parse_auth_response(['success' => true, 'token' => 'tok_x']));
    }

    public function testRestAuthReturnsNullOnFailure(): void
    {
        self::assertNull(beef_parse_auth_response(json_encode(['success' => false])));
        self::assertNull(beef_parse_auth_response('not json'));
        self::assertNull(beef_parse_auth_response(json_encode(['token' => ''])));
        self::assertNull(beef_parse_auth_response(json_encode(['success' => true]))); // no token
        self::assertNull(beef_parse_auth_response(null));
    }

    // ---- hook summarization ---------------------------------------------

    public function testSummarizeHooksFiltersOnlineOnly(): void
    {
        $raw = ['hooked-browsers' => [
            'online' => [
                '1' => [
                    'ip' => '1.2.3.4',
                    'domain' => 'login.example.com',
                    'os' => 'Windows',
                    'browser' => 'Chrome',
                    'browser.version' => '120',
                ],
            ],
            'offline' => [
                '2' => ['ip' => '5.6.7.8', 'domain' => 'old.example.com'],
            ],
        ]];
        $out = beef_summarize_hooks($raw);
        self::assertCount(1, $out);
        self::assertSame('1.2.3.4', $out[0]['ip']);
        self::assertSame('Chrome 120', $out[0]['browser']);
        self::assertSame('1', $out[0]['id']);
    }

    public function testSummarizeHooksHandlesMissingFields(): void
    {
        $raw = ['hooked-browsers' => ['online' => [
            '9' => ['ip' => '1.1.1.1'],  // no domain, no browser
        ]]];
        $out = beef_summarize_hooks($raw);
        self::assertSame('', $out[0]['domain']);
        self::assertSame('', $out[0]['browser']);
        self::assertSame('', $out[0]['os']);
    }

    public function testSummarizeHooksHandlesEmptyPayload(): void
    {
        self::assertSame([], beef_summarize_hooks([]));
        self::assertSame([], beef_summarize_hooks(['hooked-browsers' => []]));
        self::assertSame([], beef_summarize_hooks(['hooked-browsers' => ['online' => []]]));
    }

    public function testSummarizeHooksSkipsMalformedEntries(): void
    {
        $raw = ['hooked-browsers' => ['online' => [
            '1' => ['ip' => '1.2.3.4', 'domain' => 'a.example.com'],
            '2' => 'not an array',
            '3' => ['ip' => '5.6.7.8'],
        ]]];
        $out = beef_summarize_hooks($raw);
        self::assertCount(2, $out);
    }

    // ---- scope validation ------------------------------------------------

    public function testScopeValidationAcceptsExactMatch(): void
    {
        $v = beef_validate_browser_in_scope(
            ['domain' => 'acme.com', 'ip' => '1.2.3.4'],
            ['acme.com']
        );
        self::assertTrue($v['in_scope']);
    }

    public function testScopeValidationAcceptsSubdomain(): void
    {
        $v = beef_validate_browser_in_scope(
            ['domain' => 'login.acme.com', 'ip' => '1.2.3.4'],
            ['acme.com']
        );
        self::assertTrue($v['in_scope']);
    }

    public function testScopeValidationFlagsOutOfScope(): void
    {
        $v = beef_validate_browser_in_scope(
            ['domain' => 'login.other.com', 'ip' => '1.2.3.4'],
            ['acme.com']
        );
        self::assertFalse($v['in_scope']);
        self::assertSame('domain not in scope', $v['reason']);
    }

    public function testScopeValidationCaseInsensitive(): void
    {
        $v = beef_validate_browser_in_scope(
            ['domain' => 'LOGIN.Acme.COM'],
            ['acme.com']
        );
        self::assertTrue($v['in_scope']);
    }

    public function testScopeValidationRejectsSuffixCollision(): void
    {
        // "evil-acme.com" must not match scope "acme.com"
        $v = beef_validate_browser_in_scope(
            ['domain' => 'evil-acme.com'],
            ['acme.com']
        );
        self::assertFalse($v['in_scope']);
    }

    public function testScopeValidationHandlesEmptyDomain(): void
    {
        $v = beef_validate_browser_in_scope(['domain' => ''], ['acme.com']);
        self::assertFalse($v['in_scope']);
        self::assertSame('no domain', $v['reason']);
    }

    public function testScopeValidationHandlesEmptyAllowlist(): void
    {
        $v = beef_validate_browser_in_scope(['domain' => 'acme.com'], []);
        self::assertFalse($v['in_scope']);
    }

    public function testScopeValidationMatchesAnyAllowlistEntry(): void
    {
        $v = beef_validate_browser_in_scope(
            ['domain' => 'login.acme-corp.com'],
            ['other.com', 'acme-corp.com', 'third.example']
        );
        self::assertTrue($v['in_scope']);
    }

    // ---- list_hooked_browsers via injected HTTP --------------------------

    public function testListHooksUsesInjectedHttp(): void
    {
        $fake = function ($method, $url, $opts) {
            self::assertSame('GET', $method);
            self::assertStringContainsString('/api/hooks', $url);
            self::assertStringContainsString('token=tok_x', $url);
            return ['status' => 200, 'body' => json_encode(['hooked-browsers' => ['online' => [
                '1' => ['ip' => '1.2.3.4', 'domain' => 'a.example.com'],
            ]]])];
        };
        $r = beef_list_hooked_browsers('http://10.0.0.5:3000', 'tok_x', $fake);
        self::assertTrue($r['ok']);
        self::assertCount(1, $r['hooks']);
        self::assertSame('1.2.3.4', $r['hooks'][0]['ip']);
    }

    public function testListHooksSurfacesHttpError(): void
    {
        $fake = fn() => ['status' => 401, 'body' => ''];
        $r = beef_list_hooked_browsers('http://x:3000', 'tok_x', $fake);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('401', $r['err']);
    }

    public function testListHooksSurfacesNonJsonResponse(): void
    {
        $fake = fn() => ['status' => 200, 'body' => 'login page redirect'];
        $r = beef_list_hooked_browsers('http://x:3000', 'tok_x', $fake);
        self::assertFalse($r['ok']);
        self::assertStringContainsString('non-JSON', $r['err']);
    }

    public function testListHooksSurfacesTransportFailure(): void
    {
        $fake = fn() => ['status' => 0, 'body' => ''];
        $r = beef_list_hooked_browsers('http://x:3000', 'tok_x', $fake);
        self::assertFalse($r['ok']);
    }
}
