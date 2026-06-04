<?php

namespace TAPhish\Tests;

use PHPUnit\Framework\TestCase;

final class BackupPushTest extends TestCase
{
    public function testConfigValidateWebdav(): void
    {
        $ok = taphish_push_config_validate(['type' => 'webdav', 'url' => 'https://h/dav/', 'user' => 'u', 'pass' => 'p']);
        self::assertTrue($ok['ok']);
        $bad = taphish_push_config_validate(['type' => 'webdav', 'url' => 'ftp://h', 'user' => 'u']);
        self::assertFalse($bad['ok']);
        self::assertNotEmpty($bad['errors']);
    }

    public function testConfigValidateS3AndBadType(): void
    {
        $ok = taphish_push_config_validate(['type' => 's3', 'bucket' => 'b', 'region' => 'us-east-1', 'access_key' => 'k', 'secret_key' => 's']);
        self::assertTrue($ok['ok']);
        self::assertFalse(taphish_push_config_validate(['type' => 's3', 'bucket' => 'b'])['ok']);
        self::assertFalse(taphish_push_config_validate(['type' => 'ftp'])['ok']);
    }

    public function testSerializeRoundTripAndMask(): void
    {
        $cfg = ['type' => 's3', 'bucket' => 'b', 'region' => 'r', 'access_key' => 'AKIA', 'secret_key' => 'supersecretvalue'];
        $back = taphish_push_config_deserialize(taphish_push_config_serialize($cfg));
        self::assertSame($cfg, $back);
        $masked = taphish_push_config_mask($cfg);
        self::assertStringStartsWith('su', $masked['secret_key']);
        self::assertStringContainsString('*', $masked['secret_key']);
        self::assertStringNotContainsString('secretvalue', $masked['secret_key']);
        self::assertNull(taphish_push_config_deserialize(null));
    }

    public function testWebdavRequest(): void
    {
        $r = taphish_push_webdav_request(['url' => 'https://dav.example/backups', 'user' => 'alice', 'pass' => 'pw'], 'taphish-backup-20260604-010203.tapbak');
        self::assertSame('PUT', $r['method']);
        self::assertSame('https://dav.example/backups/taphish-backup-20260604-010203.tapbak', $r['url']);
        self::assertSame('Authorization: Basic ' . base64_encode('alice:pw'), $r['headers'][0]);
    }

    /**
     * AWS SigV4 "get-vanilla" test-suite vector — authoritative offline proof
     * that the whole canonical-request → string-to-sign → signing-key → signature
     * chain is correct.
     */
    public function testSigV4GetVanillaVector(): void
    {
        $auth = taphish_sigv4_authorization([
            'accessKey'     => 'AKIDEXAMPLE',
            'secret'        => 'wJalrXUtnFEMI/K7MDENG+bPxRfiCYEXAMPLEKEY',
            'region'        => 'us-east-1',
            'service'       => 'service',
            'method'        => 'GET',
            'canonicalUri'  => '/',
            'query'         => '',
            'headers'       => ['host' => 'example.amazonaws.com', 'x-amz-date' => '20150830T123600Z'],
            'signedHeaders' => 'host;x-amz-date',
            'payloadSha256' => hash('sha256', ''),
            'amzDate'       => '20150830T123600Z',
            'dateStamp'     => '20150830',
        ]);
        self::assertSame(
            'AWS4-HMAC-SHA256 Credential=AKIDEXAMPLE/20150830/us-east-1/service/aws4_request, '
            . 'SignedHeaders=host;x-amz-date, '
            . 'Signature=5fa00fa31553b73ebf1942676e86291e8372ff2a2260956d9b8aae1d763fbf31',
            $auth
        );
    }

    public function testS3RequestVirtualHostedAndPathStyle(): void
    {
        $cfg  = ['type' => 's3', 'bucket' => 'my-bucket', 'region' => 'eu-central-1', 'access_key' => 'AKIA', 'secret_key' => 'sk'];
        $sha  = hash('sha256', 'body');
        $r    = taphish_push_s3_request($cfg, 'taphish-backup-20260604-010203.tapbak', $sha, '20260604T010203Z');
        self::assertSame('https://my-bucket.s3.eu-central-1.amazonaws.com/taphish-backup-20260604-010203.tapbak', $r['url']);
        self::assertTrue((bool) preg_grep('/^Authorization: AWS4-HMAC-SHA256 /', $r['headers']));
        self::assertTrue((bool) preg_grep('/^x-amz-content-sha256: ' . $sha . '$/', $r['headers']));
        self::assertTrue((bool) preg_grep('/^x-amz-date: 20260604T010203Z$/', $r['headers']));

        $ps = taphish_push_s3_request(array_merge($cfg, ['path_style' => true]), 'f.tapbak', $sha, '20260604T010203Z');
        self::assertSame('https://s3.eu-central-1.amazonaws.com/my-bucket/f.tapbak', $ps['url']);
    }

    public function testPushSendUsesInjectedHttp(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'push_');
        file_put_contents($tmp, 'hello');
        $seen = null;
        $fake = function (array $req, string $path) use (&$seen): array {
            $seen = ['req' => $req, 'path' => $path];
            return ['ok' => true, 'status' => 200, 'error' => ''];
        };
        $req = ['method' => 'PUT', 'url' => 'https://x/y', 'headers' => ['Authorization: Basic z']];
        $res = taphish_push_send($req, $tmp, $fake);
        self::assertTrue($res['ok']);
        self::assertSame(200, $res['status']);
        self::assertSame('https://x/y', $seen['req']['url']);

        $fail = static fn (array $r, string $p): array => ['ok' => false, 'status' => 500, 'error' => 'boom'];
        self::assertFalse(taphish_push_send($req, $tmp, $fail)['ok']);
        self::assertFalse(taphish_push_send($req, $tmp . '-missing', $fake)['ok']); // missing file
        @unlink($tmp);
    }
}
