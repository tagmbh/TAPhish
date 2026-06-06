<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers the pure header-list core of spear/manager/security_headers.php.
 * The emit function calls header() (a SAPI side effect that can't be asserted
 * reliably under the CLI test runner), so the header SET is extracted into a
 * pure list this suite pins down.
 */
final class SecurityHeadersTest extends TestCase
{
    public function testListContainsEveryHardeningHeaderWithExactValue(): void
    {
        $list = taphish_security_headers_list();
        $map = [];
        foreach ($list as $line) {
            [$name, $value] = explode(': ', $line, 2);
            $map[$name] = $value;
        }

        self::assertSame('nosniff', $map['X-Content-Type-Options']);
        self::assertSame('DENY', $map['X-Frame-Options']);                       // clickjacking
        self::assertSame('strict-origin-when-cross-origin', $map['Referrer-Policy']);
        self::assertSame('max-age=31536000; includeSubDomains', $map['Strict-Transport-Security']); // 1y HSTS
        self::assertArrayHasKey('Permissions-Policy', $map);
    }

    public function testPermissionsPolicyLocksDownHighRiskApis(): void
    {
        $list = taphish_security_headers_list();
        $pp = '';
        foreach ($list as $line) {
            if (str_starts_with($line, 'Permissions-Policy:')) {
                $pp = $line;
            }
        }
        self::assertNotSame('', $pp, 'Permissions-Policy header must be present');
        foreach (['camera=()', 'microphone=()', 'geolocation=()', 'payment=()', 'usb=()'] as $directive) {
            self::assertStringContainsString($directive, $pp, "Permissions-Policy must deny {$directive}");
        }
    }

    public function testNoCspEmittedYet(): void
    {
        // CSP is deliberately deferred (inline handlers must move to
        // addEventListener first). Pin that decision so a CSP isn't added
        // half-baked without the audit.
        foreach (taphish_security_headers_list() as $line) {
            self::assertStringStartsNotWith('Content-Security-Policy', $line);
        }
    }

    public function testEveryHeaderIsWellFormedAndInjectionSafe(): void
    {
        $list = taphish_security_headers_list();
        self::assertNotEmpty($list);
        foreach ($list as $line) {
            self::assertStringContainsString(': ', $line, "header must be 'Name: value': {$line}");
            // No CR/LF anywhere — a header line with a newline would be a
            // response-splitting / header-injection vector.
            self::assertDoesNotMatchRegularExpression('/[\r\n]/', $line, "header must not contain CR/LF: {$line}");
            // Header name token must be a valid RFC 7230 field-name.
            [$name] = explode(': ', $line, 2);
            self::assertMatchesRegularExpression('/^[A-Za-z0-9-]+$/', $name, "invalid header name: {$name}");
        }
    }

    public function testHeaderNamesAreUnique(): void
    {
        $names = [];
        foreach (taphish_security_headers_list() as $line) {
            $names[] = explode(': ', $line, 2)[0];
        }
        self::assertSame(count($names), count(array_unique($names)), 'duplicate header names would let one override another');
    }
}
