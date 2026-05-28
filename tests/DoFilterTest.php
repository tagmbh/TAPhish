<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class DoFilterTest extends TestCase
{
    public function testAlphaNumStripsSymbolsAndWhitespace(): void
    {
        self::assertSame('abc123', doFilter("abc 123!@#", 'ALPHA_NUM'));
        self::assertSame('', doFilter('-_/.', 'ALPHA_NUM'));
    }

    public function testAlphaStripsDigits(): void
    {
        self::assertSame('abc', doFilter('abc123', 'ALPHA'));
        self::assertSame('', doFilter('12345', 'ALPHA'));
    }

    public function testNumStripsLetters(): void
    {
        self::assertSame('123', doFilter('abc123', 'NUM'));
        self::assertSame('', doFilter('hello', 'NUM'));
    }

    public function testUnknownTypeReturnsInputUnchanged(): void
    {
        $payload = "a b c; rm -rf /";
        self::assertSame($payload, doFilter($payload, 'NOT_A_REAL_TYPE'));
    }

    public function testAlphaNumIsSafeForShellInterpolation(): void
    {
        // Regression guard: a numeric-looking campaign_id sanitized with NUM
        // must never contain shell metacharacters.
        $sanitized = doFilter('1; rm -rf /', 'NUM');
        self::assertSame('1', $sanitized);
        self::assertDoesNotMatchRegularExpression('/[^0-9]/', $sanitized);
    }
}
