<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testMakeTokenReturnsHex64(): void
    {
        $t = _csrf_make_token();
        self::assertSame(64, strlen($t));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $t);
    }

    public function testMakeTokenIsUniqueAcrossCalls(): void
    {
        $a = _csrf_make_token();
        $b = _csrf_make_token();
        self::assertNotSame($a, $b);
    }

    public function testCompareAcceptsMatchingTokens(): void
    {
        $t = _csrf_make_token();
        self::assertTrue(_csrf_compare($t, $t));
    }

    public function testCompareRejectsMismatch(): void
    {
        $a = _csrf_make_token();
        $b = _csrf_make_token();
        self::assertFalse(_csrf_compare($a, $b));
    }

    public function testCompareRejectsWrongLength(): void
    {
        self::assertFalse(_csrf_compare('abc', 'abc'));
        self::assertFalse(_csrf_compare(str_repeat('a', 64), str_repeat('a', 63)));
    }

    public function testCompareRejectsNonHex(): void
    {
        $bad = str_repeat('z', 64);
        $good = str_repeat('a', 64);
        self::assertFalse(_csrf_compare($good, $bad));
    }

    public function testCompareRejectsNulls(): void
    {
        self::assertFalse(_csrf_compare(null, null));
        self::assertFalse(_csrf_compare(_csrf_make_token(), null));
    }

    public function testTokenIsStableAcrossCallsWithSameStore(): void
    {
        $store = [];
        $first  = csrf_token($store);
        $second = csrf_token($store);
        self::assertSame($first, $second);
    }

    public function testRotateReplacesToken(): void
    {
        $store = [];
        $a = csrf_token($store);
        $b = csrf_rotate($store);
        self::assertNotSame($a, $b);
        self::assertSame($b, $store['_csrf']);
    }

    public function testVerifyAcceptsCurrentToken(): void
    {
        $store = [];
        $t = csrf_token($store);
        self::assertTrue(csrf_verify($t, $store));
    }

    public function testVerifyRejectsForeignToken(): void
    {
        $store = [];
        csrf_token($store);
        self::assertFalse(csrf_verify(_csrf_make_token(), $store));
    }

    public function testVerifyRejectsEmptyStore(): void
    {
        $store = [];
        self::assertFalse(csrf_verify(_csrf_make_token(), $store));
    }

    public function testExtractPrefersHeader(): void
    {
        $t = _csrf_make_token();
        $headers = ['X-CSRF-Token' => $t];
        $body    = json_encode(['_csrf' => 'override-attempt']);
        $post    = ['_csrf' => 'another-attempt'];
        self::assertSame($t, csrf_extract_from_request($headers, $body, $post));
    }

    public function testExtractHeaderNameIsCaseInsensitive(): void
    {
        $t = _csrf_make_token();
        self::assertSame($t, csrf_extract_from_request(['x-csrf-token' => $t], '', []));
    }

    public function testExtractFallsBackToJsonBody(): void
    {
        $t = _csrf_make_token();
        $body = json_encode(['_csrf' => $t, 'other' => 'thing']);
        self::assertSame($t, csrf_extract_from_request([], $body, []));
    }

    public function testExtractFallsBackToFormPost(): void
    {
        $t = _csrf_make_token();
        self::assertSame($t, csrf_extract_from_request([], '', ['_csrf' => $t]));
    }

    public function testExtractReturnsNullWhenAbsent(): void
    {
        self::assertNull(csrf_extract_from_request([], '', []));
        self::assertNull(csrf_extract_from_request([], '{"x":1}', []));
    }
}
