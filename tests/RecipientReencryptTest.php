<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class RecipientReencryptTest extends TestCase
{
    /** Fixed 32-byte test key (matches SecretAtRestTest convention). */
    private function key(): string
    {
        return str_repeat("\x42", 32);
    }

    /** Realistic seal/unseal/isEnc closures wired to the fixed key (no disk key). */
    private function crypto(): array
    {
        $key = $this->key();
        return [
            'seal'   => static fn (string $pt): string => secret_at_rest_encrypt($pt, $key) ?? $pt,
            'unseal' => static function (?string $s) use ($key): ?string {
                if ($s === null || $s === '') {
                    return $s;
                }
                if (!secret_at_rest_is_encrypted($s)) {
                    return $s;
                }
                return secret_at_rest_decrypt($s, $key);
            },
            'isEnc'  => static fn (?string $s): bool => secret_at_rest_is_encrypted($s),
        ];
    }

    public function testEmptyIsSkipped(): void
    {
        $c = $this->crypto();
        foreach ([null, ''] as $v) {
            $p = taphish_reencrypt_plan_user_data($v, $c['seal'], $c['unseal'], $c['isEnc']);
            self::assertSame('skip-empty', $p['action']);
            self::assertNull($p['sealed']);
        }
    }

    public function testAlreadySealedIsSkipped(): void
    {
        $c = $this->crypto();
        $sealed = ($c['seal'])('[{"uid":"abc","email":"a@b.com"}]');
        self::assertStringStartsWith('enc1:', $sealed);
        $p = taphish_reencrypt_plan_user_data($sealed, $c['seal'], $c['unseal'], $c['isEnc']);
        self::assertSame('skip-sealed', $p['action']);
        self::assertNull($p['sealed']);
    }

    public function testPlaintextRecipientArrayIsSealed(): void
    {
        $c  = $this->crypto();
        $pt = '[{"uid":"abc","fname":"Ann","email":"a@b.com"}]';
        $p  = taphish_reencrypt_plan_user_data($pt, $c['seal'], $c['unseal'], $c['isEnc']);
        self::assertSame('seal', $p['action']);
        self::assertFalse($p['suspect']);
        self::assertStringStartsWith('enc1:', (string) $p['sealed']);
        self::assertSame($pt, ($c['unseal'])($p['sealed']));
    }

    public function testNonArrayPlaintextIsSealedButSuspect(): void
    {
        $c = $this->crypto();
        foreach (['not json at all', '123'] as $pt) {
            $p = taphish_reencrypt_plan_user_data($pt, $c['seal'], $c['unseal'], $c['isEnc']);
            self::assertSame('seal', $p['action'], "value: $pt");
            self::assertTrue($p['suspect'], "value: $pt");
            self::assertSame($pt, ($c['unseal'])($p['sealed']));
        }
    }

    public function testSealPassthroughBecomesError(): void
    {
        $c       = $this->crypto();
        $badSeal = static fn (string $pt): string => $pt; // simulate key-unavailable passthrough
        $p = taphish_reencrypt_plan_user_data('[{"email":"a@b.com"}]', $badSeal, $c['unseal'], $c['isEnc']);
        self::assertSame('error', $p['action']);
        self::assertNull($p['sealed']);
    }

    public function testRoundTripMismatchBecomesError(): void
    {
        $c          = $this->crypto();
        $liarUnseal = static fn (?string $s): ?string => 'DIFFERENT';
        $p = taphish_reencrypt_plan_user_data('[{"email":"a@b.com"}]', $c['seal'], $liarUnseal, $c['isEnc']);
        self::assertSame('error', $p['action']);
        self::assertNull($p['sealed']);
    }
}
