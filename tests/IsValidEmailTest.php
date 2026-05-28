<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class IsValidEmailTest extends TestCase
{
    public function testEmptyIsRejected(): void
    {
        self::assertFalse(isValidEmail(''));
        self::assertFalse(isValidEmail(null));
    }

    public function testCommonValidAddresses(): void
    {
        self::assertNotFalse(isValidEmail('user@example.com'));
        self::assertNotFalse(isValidEmail('first.last+tag@example.co.uk'));
        self::assertNotFalse(isValidEmail('a@b.de'));
    }

    public function testObviouslyInvalidAddresses(): void
    {
        self::assertFalse((bool) isValidEmail('not-an-email'));
        self::assertFalse((bool) isValidEmail('@example.com'));
        self::assertFalse((bool) isValidEmail('user@'));
        self::assertFalse((bool) isValidEmail('user @example.com'));
    }
}
