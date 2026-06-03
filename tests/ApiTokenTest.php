<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Phase 3.48: pure tests for the API-token format/parse + secret verification.
 * Mint / authenticate / list / revoke are DB-backed (integration tier); the
 * verification logic is exercised here against a real bcrypt hash.
 */
final class ApiTokenTest extends TestCase
{
    public function testHelpersAreDefined(): void
    {
        foreach ([
            'taphish_api_token_ensure_table', 'taphish_api_token_format',
            'taphish_api_token_parse', 'taphish_api_token_verify_secret',
            'taphish_api_token_mint', 'taphish_api_token_authenticate',
            'taphish_api_token_list', 'taphish_api_token_revoke',
            'taphish_extract_bearer_token', 'taphish_can', 'taphish_current_user_role',
        ] as $fn) {
            self::assertTrue(function_exists($fn), "missing: {$fn}");
        }
    }

    public function testExtractBearerTokenFromAuthorizationHeader(): void
    {
        $saved = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer tphtk_7_Abc123Def456Ghi789Jkl';
        self::assertSame('tphtk_7_Abc123Def456Ghi789Jkl', taphish_extract_bearer_token());

        $_SERVER['HTTP_AUTHORIZATION'] = 'bearer   tok123';   // case + spacing tolerant
        self::assertSame('tok123', taphish_extract_bearer_token());

        $_SERVER['HTTP_AUTHORIZATION'] = 'Basic abc';         // not a bearer
        self::assertSame('', taphish_extract_bearer_token());

        unset($_SERVER['HTTP_AUTHORIZATION']);
        self::assertSame('', taphish_extract_bearer_token());

        if ($saved !== null) { $_SERVER['HTTP_AUTHORIZATION'] = $saved; }
    }

    public function testFormatAndParseRoundTrip(): void
    {
        $secret = 'Abc123Def456Ghi789Jkl';   // 21 chars, [A-Za-z0-9]
        $token  = taphish_api_token_format(42, $secret);
        self::assertSame('tphtk_42_' . $secret, $token);

        $p = taphish_api_token_parse($token);
        self::assertNotNull($p);
        self::assertSame(42, $p['id']);
        self::assertSame($secret, $p['secret']);
    }

    public function testParseRejectsMalformedTokens(): void
    {
        self::assertNull(taphish_api_token_parse('garbage'));
        self::assertNull(taphish_api_token_parse('tphtk_x_Abc123Def456Ghi789Jkl')); // non-numeric id
        self::assertNull(taphish_api_token_parse('tphtk_42_short'));                // secret too short
        self::assertNull(taphish_api_token_parse('tphtk_42_has bad chars!!'));      // bad chars
        self::assertNull(taphish_api_token_parse(''));
    }

    public function testVerifySecretAgainstRealBcryptHash(): void
    {
        $secret = 'Abc123Def456Ghi789Jkl';
        $hash   = hash_user_password($secret);

        self::assertTrue(taphish_api_token_verify_secret($secret, ['token_hash' => $hash]));
        self::assertFalse(taphish_api_token_verify_secret('wrong-secret', ['token_hash' => $hash]));
    }

    public function testVerifySecretRejectsRevokedOrMissing(): void
    {
        $secret = 'Abc123Def456Ghi789Jkl';
        $hash   = hash_user_password($secret);

        self::assertFalse(taphish_api_token_verify_secret($secret, null));
        self::assertFalse(taphish_api_token_verify_secret($secret, ['token_hash' => $hash, 'revoked_at' => 1700000000]));
        self::assertFalse(taphish_api_token_verify_secret($secret, ['no_hash' => 'x']));
    }
}
