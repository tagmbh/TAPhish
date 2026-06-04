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

    public function testRunTalliesMixedBatch(): void
    {
        $c        = $this->crypto();
        $existing = ($c['seal'])('[{"email":"x@y.com"}]');
        $rows = [
            ['user_group_id' => 1, 'user_data' => null],                       // skip-empty
            ['user_group_id' => 2, 'user_data' => ''],                         // skip-empty
            ['user_group_id' => 3, 'user_data' => $existing],                  // skip-sealed
            ['user_group_id' => 4, 'user_data' => '[{"email":"a@b.com"}]'],    // seal
            ['user_group_id' => 5, 'user_data' => 'garbage'],                  // seal + suspect
        ];
        $applied = [];
        $apply   = function ($id, string $sealed) use (&$applied): bool {
            $applied[$id] = $sealed;
            return true;
        };

        $out = taphish_reencrypt_run($rows, $apply, $c, false);

        self::assertSame(5, $out['scanned']);
        self::assertSame(2, $out['skipped_empty']);
        self::assertSame(1, $out['skipped_sealed']);
        self::assertSame(2, $out['sealed']);
        self::assertSame(1, $out['suspect']);
        self::assertSame(0, $out['errors']);
        self::assertSame(0, $out['write_failures']);
        self::assertArrayHasKey(4, $applied);
        self::assertArrayHasKey(5, $applied);
        self::assertStringStartsWith('enc1:', $applied[4]);
        self::assertContains(5, $out['suspect_ids']);
    }

    public function testDryRunWritesNothingButCounts(): void
    {
        $c       = $this->crypto();
        $rows    = [['user_group_id' => 4, 'user_data' => '[{"email":"a@b.com"}]']];
        $applied = [];
        $apply   = function ($id, string $sealed) use (&$applied): bool {
            $applied[$id] = $sealed;
            return true;
        };

        $out = taphish_reencrypt_run($rows, $apply, $c, true);

        self::assertSame(1, $out['sealed']);
        self::assertSame([], $applied);
    }

    public function testWriteFailureCountedNotSealed(): void
    {
        $c     = $this->crypto();
        $rows  = [['user_group_id' => 4, 'user_data' => '[{"email":"a@b.com"}]']];
        $apply = static fn ($id, string $sealed): bool => false;

        $out = taphish_reencrypt_run($rows, $apply, $c, false);

        self::assertSame(0, $out['sealed']);
        self::assertSame(1, $out['write_failures']);
    }

    public function testErrorRowDoesNotWrite(): void
    {
        $c         = $this->crypto();
        $badCrypto = ['seal' => static fn (string $pt): string => $pt, 'unseal' => $c['unseal'], 'isEnc' => $c['isEnc']];
        $rows      = [['user_group_id' => 9, 'user_data' => '[{"email":"a@b.com"}]']];
        $applied   = [];
        $apply     = function ($id, string $sealed) use (&$applied): bool {
            $applied[$id] = $sealed;
            return true;
        };

        $out = taphish_reencrypt_run($rows, $apply, $badCrypto, false);

        self::assertSame(1, $out['errors']);
        self::assertSame(0, $out['sealed']);
        self::assertSame([], $applied);
        self::assertContains(9, $out['error_ids']);
    }

    public function testFormatSummaryIncludesCountsAndDryRunBanner(): void
    {
        $counts = [
            'scanned' => 42, 'skipped_sealed' => 38, 'skipped_empty' => 1,
            'sealed' => 3, 'suspect' => 1, 'errors' => 0, 'write_failures' => 0,
            'suspect_ids' => [17], 'error_ids' => [], 'sealed_ids' => [4, 5, 17],
        ];
        $out = taphish_reencrypt_format_summary($counts, true);
        self::assertStringContainsString('Recipient PII re-encrypt sweep', $out);
        self::assertStringContainsString('scanned:', $out);
        self::assertStringContainsString('38 (skipped)', $out);
        self::assertStringContainsString('17', $out);
        self::assertStringContainsString('[DRY RUN', $out);
    }

    public function testFormatSummaryNoDryRunBannerWhenLive(): void
    {
        $counts = [
            'scanned' => 1, 'skipped_sealed' => 0, 'skipped_empty' => 0,
            'sealed' => 1, 'suspect' => 0, 'errors' => 0, 'write_failures' => 0,
            'suspect_ids' => [], 'error_ids' => [], 'sealed_ids' => [1],
        ];
        $out = taphish_reencrypt_format_summary($counts, false);
        self::assertStringNotContainsString('DRY RUN', $out);
    }
}
