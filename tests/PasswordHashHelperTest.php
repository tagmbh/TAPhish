<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class PasswordHashHelperTest extends TestCase
{
    public function testHashProducesBcryptString(): void
    {
        $h = hash_user_password('correct horse battery staple');
        self::assertStringStartsWith('$2y$', $h);
        self::assertSame(60, strlen($h));
    }

    public function testHashIsSaltedAndUnique(): void
    {
        $a = hash_user_password('hunter2');
        $b = hash_user_password('hunter2');
        self::assertNotSame($a, $b, 'bcrypt should salt every call');
        self::assertTrue(password_verify('hunter2', $a));
        self::assertTrue(password_verify('hunter2', $b));
    }

    public function testIsLegacySha256DetectsHexDigest(): void
    {
        $sha = hash('sha256', 'sniperphish');
        self::assertTrue(is_legacy_sha256_hash($sha));
    }

    public function testIsLegacySha256RejectsBcrypt(): void
    {
        $bc = password_hash('x', PASSWORD_BCRYPT);
        self::assertFalse(is_legacy_sha256_hash($bc));
    }

    public function testIsLegacySha256RejectsWrongLength(): void
    {
        self::assertFalse(is_legacy_sha256_hash(str_repeat('a', 63)));
        self::assertFalse(is_legacy_sha256_hash(str_repeat('a', 65)));
        self::assertFalse(is_legacy_sha256_hash(''));
        self::assertFalse(is_legacy_sha256_hash(null));
    }

    public function testIsLegacySha256RejectsNonHex(): void
    {
        self::assertFalse(is_legacy_sha256_hash(str_repeat('g', 64)));
    }

    public function testVerifyAcceptsBcrypt(): void
    {
        $bc = hash_user_password('pw');
        self::assertTrue(verify_user_password('pw', $bc));
        self::assertFalse(verify_user_password('pwx', $bc));
    }

    public function testVerifyAcceptsLegacySha256(): void
    {
        $legacy = hash('sha256', 'sniperphish');
        self::assertTrue(verify_user_password('sniperphish', $legacy));
        self::assertFalse(verify_user_password('wrong', $legacy));
    }

    public function testVerifyRejectsEmptyStored(): void
    {
        self::assertFalse(verify_user_password('any', ''));
        self::assertFalse(verify_user_password('any', null));
    }

    public function testShouldRehashFlagsLegacySha256(): void
    {
        self::assertTrue(password_should_rehash(hash('sha256', 'x')));
    }

    public function testShouldRehashIsFalseForFreshBcrypt(): void
    {
        $bc = hash_user_password('pw');
        self::assertFalse(password_should_rehash($bc));
    }

    public function testShouldRehashFlagsEmptyOrNull(): void
    {
        self::assertTrue(password_should_rehash(''));
        self::assertTrue(password_should_rehash(null));
    }

    public function testMakeSecureTokenReturns64HexChars(): void
    {
        $t = make_secure_token();
        self::assertSame(64, strlen($t));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $t);
    }

    public function testMakeSecureTokenIsUnique(): void
    {
        self::assertNotSame(make_secure_token(), make_secure_token());
    }
}
