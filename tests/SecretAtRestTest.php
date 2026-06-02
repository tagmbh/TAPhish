<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class SecretAtRestTest extends TestCase
{
    private function key(): string
    {
        // Reproducible 32-byte key for the tests. Different from any
        // operator's real key.
        return str_repeat("\x42", 32);
    }

    public function testEncryptRoundTrip(): void
    {
        $key = $this->key();
        $enc = secret_at_rest_encrypt('super secret pwd!', $key);
        self::assertIsString($enc);
        self::assertStringStartsWith('enc1:', $enc);
        self::assertSame('super secret pwd!', secret_at_rest_decrypt($enc, $key));
    }

    public function testEncryptIsNonDeterministic(): void
    {
        $key = $this->key();
        $a = secret_at_rest_encrypt('same pt', $key);
        $b = secret_at_rest_encrypt('same pt', $key);
        self::assertNotSame($a, $b, 'random IV should produce different ciphertexts');
        // …but both decrypt back to the original.
        self::assertSame('same pt', secret_at_rest_decrypt($a, $key));
        self::assertSame('same pt', secret_at_rest_decrypt($b, $key));
    }

    public function testEncryptRejectsWrongKeyLength(): void
    {
        self::assertNull(secret_at_rest_encrypt('x', 'short'));
        self::assertNull(secret_at_rest_encrypt('x', str_repeat('A', 31)));
        self::assertNull(secret_at_rest_encrypt('x', str_repeat('A', 33)));
    }

    public function testDecryptRejectsTamperedCiphertext(): void
    {
        $key = $this->key();
        $enc = secret_at_rest_encrypt('hello', $key);
        // Flip the last byte of the base64 payload.
        $payload = substr($enc, strlen('enc1:'));
        $payload[strlen($payload) - 2] = ($payload[strlen($payload) - 2] === 'A') ? 'B' : 'A';
        $tampered = 'enc1:' . $payload;
        self::assertNull(secret_at_rest_decrypt($tampered, $key));
    }

    public function testDecryptRejectsWrongKey(): void
    {
        $enc = secret_at_rest_encrypt('hello', $this->key());
        self::assertNull(secret_at_rest_decrypt($enc, str_repeat("\x99", 32)));
    }

    public function testDecryptRejectsMissingPrefix(): void
    {
        self::assertNull(secret_at_rest_decrypt('plaintext value', $this->key()));
    }

    public function testDecryptRejectsTooShortPayload(): void
    {
        self::assertNull(secret_at_rest_decrypt('enc1:' . base64_encode('short'), $this->key()));
    }

    public function testIsEncryptedDetectsPrefix(): void
    {
        self::assertTrue(secret_at_rest_is_encrypted('enc1:abcdef'));
        self::assertFalse(secret_at_rest_is_encrypted('plain'));
        self::assertFalse(secret_at_rest_is_encrypted(''));
        self::assertFalse(secret_at_rest_is_encrypted(null));
    }

    public function testPassthroughReturnsLegacyPlaintextUnchanged(): void
    {
        self::assertSame('legacy-pwd', secret_at_rest_passthrough_decrypt('legacy-pwd', $this->key()));
    }

    public function testPassthroughDecryptsEnvelope(): void
    {
        $enc = secret_at_rest_encrypt('real-pwd', $this->key());
        self::assertSame('real-pwd', secret_at_rest_passthrough_decrypt($enc, $this->key()));
    }

    public function testPassthroughEmptyAndNullPassThrough(): void
    {
        self::assertSame('', secret_at_rest_passthrough_decrypt('', $this->key()));
        self::assertNull(secret_at_rest_passthrough_decrypt(null, $this->key()));
    }

    public function testPassthroughReturnsNullOnTamperedEnvelope(): void
    {
        $enc = secret_at_rest_encrypt('real-pwd', $this->key());
        $payload = substr($enc, strlen('enc1:'));
        $payload[strlen($payload) - 2] = ($payload[strlen($payload) - 2] === 'A') ? 'B' : 'A';
        $tampered = 'enc1:' . $payload;
        self::assertNull(secret_at_rest_passthrough_decrypt($tampered, $this->key()));
    }

    public function testLongPlaintextRoundTrip(): void
    {
        $key = $this->key();
        $pt = str_repeat('long secret block — éàü 漢字 🔐 ', 50);
        $enc = secret_at_rest_encrypt($pt, $key);
        self::assertSame($pt, secret_at_rest_decrypt($enc, $key));
    }

    // Phase 3.38: recipient_data_{seal,unseal} passthrough behavior.
    // The full encrypt round-trip needs the on-disk key file, which a
    // unit test shouldn't touch. We test the passthrough paths that
    // run BEFORE the key lookup: empty input + already-plaintext input.

    public function testRecipientDataUnsealReturnsNullForNull(): void
    {
        self::assertNull(recipient_data_unseal(null));
    }

    public function testRecipientDataUnsealReturnsEmptyForEmpty(): void
    {
        self::assertSame('', recipient_data_unseal(''));
    }

    public function testRecipientDataUnsealPassesLegacyPlaintextThrough(): void
    {
        $legacy = '[{"uid":"a","fname":"Test","email":"t@example"}]';
        self::assertSame($legacy, recipient_data_unseal($legacy));
    }

    public function testRecipientDataSealReturnsEmptyForEmpty(): void
    {
        self::assertSame('', recipient_data_seal(''));
    }
}
