<?php
/**
 * Phase 3.50 — Encrypted DB backup: chunked streaming container (.tapbak).
 *
 * Format:  magic "TAPBAK1\n", then repeated [uint32 BE frameLen][frameLen bytes = enc1: envelope].
 * Each frame is secret_at_rest_encrypt() of one <= chunk-size plaintext slice → memory bounded.
 *
 * Pure: all bytes move through injected reader/writer/crypto closures (file handles in
 * the CLI, in-memory buffers in tests).
 */

if (!defined('TAPHISH_BACKUP_MAGIC')) {
    define('TAPHISH_BACKUP_MAGIC', "TAPBAK1\n");
}
if (!defined('TAPHISH_BACKUP_CHUNK')) {
    define('TAPHISH_BACKUP_CHUNK', 1048576); // 1 MiB plaintext per frame
}

if (!function_exists('taphish_backup_read_exact')) {
    /**
     * Pull exactly $n bytes from $read($want) (which may return short reads),
     * stopping early only at EOF. Returns whatever was gathered (caller checks length).
     */
    function taphish_backup_read_exact(callable $read, int $n): string
    {
        $buf = '';
        while (strlen($buf) < $n) {
            $part = $read($n - strlen($buf));
            if ($part === '' || $part === null) {
                break;
            }
            $buf .= $part;
        }
        return $buf;
    }
}

if (!function_exists('taphish_backup_encrypt_stream')) {
    /**
     * @param callable $read  fn(int $n): string   up to $n plaintext bytes, '' at EOF
     * @param callable $write fn(string): void     append container bytes
     * @param callable $encFn fn(string): ?string  real: fn($p)=>secret_at_rest_encrypt($p,$key)
     * @return bool false if any chunk fails to encrypt (caller discards partial output)
     */
    function taphish_backup_encrypt_stream(callable $read, callable $write, callable $encFn, int $chunk = TAPHISH_BACKUP_CHUNK): bool
    {
        $write(TAPHISH_BACKUP_MAGIC);
        while (true) {
            $plain = taphish_backup_read_exact($read, $chunk);
            if ($plain === '') {
                break;
            }
            $env = $encFn($plain);
            if (!is_string($env) || $env === '') {
                return false;
            }
            $write(pack('N', strlen($env)) . $env);
            if (strlen($plain) < $chunk) {
                break; // last (short) chunk consumed
            }
        }
        return true;
    }
}

if (!function_exists('taphish_backup_decrypt_stream')) {
    /**
     * @param callable $read  fn(int $n): string   up to $n container bytes, '' at EOF
     * @param callable $write fn(string): void     append recovered plaintext
     * @param callable $decFn fn(string): ?string  real: fn($e)=>secret_at_rest_decrypt($e,$key)
     * @return bool false on bad magic / truncation / decrypt failure
     */
    function taphish_backup_decrypt_stream(callable $read, callable $write, callable $decFn): bool
    {
        $magic = taphish_backup_read_exact($read, strlen(TAPHISH_BACKUP_MAGIC));
        if ($magic !== TAPHISH_BACKUP_MAGIC) {
            return false;
        }
        while (true) {
            $lenRaw = taphish_backup_read_exact($read, 4);
            if ($lenRaw === '') {
                return true; // clean EOF at a frame boundary
            }
            if (strlen($lenRaw) !== 4) {
                return false; // truncated length prefix
            }
            $len = unpack('N', $lenRaw)[1];
            if ($len <= 0) {
                return false;
            }
            $env = taphish_backup_read_exact($read, $len);
            if (strlen($env) !== $len) {
                return false; // truncated frame
            }
            $plain = $decFn($env);
            if (!is_string($plain)) {
                return false; // wrong key / tamper
            }
            $write($plain);
        }
    }
}
