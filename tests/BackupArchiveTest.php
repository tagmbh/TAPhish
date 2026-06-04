<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class BackupArchiveTest extends TestCase
{
    private function key(): string
    {
        return str_repeat("\x42", 32);
    }

    /** In-memory $read($n) over a fixed string. */
    private function reader(string $data): callable
    {
        $pos = 0;
        return function (int $n) use (&$pos, $data): string {
            if ($pos >= strlen($data)) {
                return '';
            }
            $part = substr($data, $pos, $n);
            $pos += strlen($part);
            return $part;
        };
    }

    private function encFn(): callable
    {
        $key = $this->key();
        return static fn (string $p): ?string => secret_at_rest_encrypt($p, $key);
    }

    private function decFn(): callable
    {
        $key = $this->key();
        return static fn (string $e): ?string => secret_at_rest_decrypt($e, $key);
    }

    private function container(string $payload, int $chunk): string
    {
        $out   = '';
        $write = function (string $b) use (&$out): void { $out .= $b; };
        $ok    = taphish_backup_encrypt_stream($this->reader($payload), $write, $this->encFn(), $chunk);
        self::assertTrue($ok);
        return $out;
    }

    public function testRoundTripMultiChunk(): void
    {
        $payload = random_bytes(5000); // many frames at chunk=512
        $blob    = $this->container($payload, 512);
        self::assertStringStartsWith("TAPBAK1\n", $blob);

        $rec   = '';
        $write = function (string $b) use (&$rec): void { $rec .= $b; };
        $ok    = taphish_backup_decrypt_stream($this->reader($blob), $write, $this->decFn());
        self::assertTrue($ok);
        self::assertSame($payload, $rec);
    }

    public function testEmptyPayloadHeaderOnly(): void
    {
        $blob = $this->container('', 512);
        self::assertSame("TAPBAK1\n", $blob);

        $rec   = '';
        $write = function (string $b) use (&$rec): void { $rec .= $b; };
        $ok    = taphish_backup_decrypt_stream($this->reader($blob), $write, $this->decFn());
        self::assertTrue($ok);
        self::assertSame('', $rec);
    }

    public function testWrongKeyFails(): void
    {
        $blob   = $this->container('hello world payload', 512);
        $badKey = str_repeat("\x00", 32);
        $decBad = static fn (string $e): ?string => secret_at_rest_decrypt($e, $badKey);

        $rec   = '';
        $write = function (string $b) use (&$rec): void { $rec .= $b; };
        $ok    = taphish_backup_decrypt_stream($this->reader($blob), $write, $decBad);
        self::assertFalse($ok);
    }

    public function testBadMagicFails(): void
    {
        $rec   = '';
        $write = function (string $b) use (&$rec): void { $rec .= $b; };
        $ok    = taphish_backup_decrypt_stream($this->reader('NOPE....frames'), $write, $this->decFn());
        self::assertFalse($ok);
    }

    public function testTamperedFrameFails(): void
    {
        $blob = $this->container('some secret backup bytes', 512);
        // flip a byte well past the 8-byte magic + 4-byte length prefix
        $blob[20] = $blob[20] === 'A' ? 'B' : 'A';

        $rec   = '';
        $write = function (string $b) use (&$rec): void { $rec .= $b; };
        $ok    = taphish_backup_decrypt_stream($this->reader($blob), $write, $this->decFn());
        self::assertFalse($ok);
    }

    public function testTruncatedContainerFails(): void
    {
        $blob  = $this->container('payload that spans frames here', 8);
        $trunc = substr($blob, 0, strlen($blob) - 5); // cut mid last frame

        $rec   = '';
        $write = function (string $b) use (&$rec): void { $rec .= $b; };
        $ok    = taphish_backup_decrypt_stream($this->reader($trunc), $write, $this->decFn());
        self::assertFalse($ok);
    }
}
