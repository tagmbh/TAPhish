<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * P3 — capture-field decoding. The web-mail dashboard pushed each Field-<name>
 * value once PER submission, so a victim who re-posted 24× showed "email ×24"
 * and a password ×8, with a duplicate column per page. taphish_decode_capture_fields
 * collapses ALL of a victim's submissions into one clean map: field => the
 * DISTINCT non-empty values in first-seen order (usually a single value). The
 * display layer then renders one column per logical field, one row per victim.
 */
final class CaptureFieldsTest extends TestCase
{
    public function testSingleSubmissionYieldsOneValuePerField(): void
    {
        $out = taphish_decode_capture_fields([
            ['email' => 'a@x.com', 'password' => 'hunter2'],
        ]);
        self::assertSame(['a@x.com'], $out['email']);
        self::assertSame(['hunter2'], $out['password']);
    }

    public function testRepeatedIdenticalSubmissionsCollapseToOne(): void
    {
        // The core bug: 24 identical re-posts must NOT become email ×24.
        $subs = array_fill(0, 24, ['email' => 'a@x.com', 'password' => 'hunter2']);
        $out = taphish_decode_capture_fields($subs);
        self::assertSame(['a@x.com'], $out['email']);
        self::assertSame(['hunter2'], $out['password']);
    }

    public function testDistinctValuesPreservedInFirstSeenOrder(): void
    {
        $out = taphish_decode_capture_fields([
            ['password' => 'try1'],
            ['password' => 'try2'],
            ['password' => 'try1'],   // duplicate of the first → not re-added
        ]);
        self::assertSame(['try1', 'try2'], $out['password']);
    }

    public function testEmptyAndWhitespaceValuesSkipped(): void
    {
        $out = taphish_decode_capture_fields([
            ['email' => '', 'password' => '   ', 'otp' => '123456'],
        ]);
        self::assertArrayNotHasKey('email', $out);
        self::assertArrayNotHasKey('password', $out);
        self::assertSame(['123456'], $out['otp']);
    }

    public function testMergesFieldsAcrossPages(): void
    {
        // Page-1 captured email, Page-2 captured password, Page-3 OTP — one victim.
        $out = taphish_decode_capture_fields([
            ['email' => 'a@x.com'],
            ['password' => 'hunter2'],
            ['code_2fa' => '999111'],
        ]);
        self::assertSame(['a@x.com'], $out['email']);
        self::assertSame(['hunter2'], $out['password']);
        self::assertSame(['999111'], $out['code_2fa']);
    }

    public function testNonArraySubmissionsIgnored(): void
    {
        $out = taphish_decode_capture_fields(['garbage', null, ['email' => 'a@x.com']]);
        self::assertSame(['a@x.com'], $out['email']);
    }

    public function testDisplayJoinsDistinctValues(): void
    {
        self::assertSame('a@x.com', taphish_capture_field_display(['a@x.com']));
        self::assertSame('try1, try2', taphish_capture_field_display(['try1', 'try2']));
        self::assertSame('', taphish_capture_field_display([]));
    }

    public function testEmptyInputYieldsEmpty(): void
    {
        self::assertSame([], taphish_decode_capture_fields([]));
    }
}
