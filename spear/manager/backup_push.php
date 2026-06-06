<?php
/**
 * Phase 3.50c — Off-host backup push (S3 + WebDAV).
 *
 * Pure request-building (config, WebDAV PUT, AWS SigV4 + S3 PUT) plus an injectable
 * curl transport that streams a file with HTTP PUT. No clock/file reads in the pure
 * layer — the CLI passes in payloadSha256 + amzDate so the signing is deterministic
 * and unit-testable (verified against the AWS SigV4 "get-vanilla" vector).
 *
 * Driver: spear/manager/cli/backup_run.php (--push), backup_push_config.php
 * Design: docs/superpowers/specs/2026-06-04-phase-3.50c-offhost-push-design.md
 */

if (!function_exists('taphish_push_config_validate')) {
    /** @return array{ok:bool,errors:string[],cfg:array} */
    function taphish_push_config_validate(array $cfg): array
    {
        $errors = [];
        $type = (string) ($cfg['type'] ?? '');
        if ($type === 'webdav') {
            foreach (['url', 'user', 'pass'] as $k) {
                if (empty($cfg[$k])) {
                    $errors[] = "missing {$k}";
                }
            }
            if (!empty($cfg['url']) && !preg_match('#^https?://#', (string) $cfg['url'])) {
                $errors[] = 'url must be http(s)://';
            }
        } elseif ($type === 's3') {
            foreach (['bucket', 'region', 'access_key', 'secret_key'] as $k) {
                if (empty($cfg[$k])) {
                    $errors[] = "missing {$k}";
                }
            }
        } else {
            $errors[] = 'type must be webdav or s3';
        }
        return ['ok' => $errors === [], 'errors' => $errors, 'cfg' => $cfg];
    }
}

if (!function_exists('taphish_push_config_serialize')) {
    function taphish_push_config_serialize(array $cfg): string
    {
        return (string) json_encode($cfg);
    }
}

if (!function_exists('taphish_push_config_deserialize')) {
    function taphish_push_config_deserialize(?string $json): ?array
    {
        if ($json === null || $json === '') {
            return null;
        }
        $d = json_decode($json, true);
        return is_array($d) ? $d : null;
    }
}

if (!function_exists('taphish_push_config_mask')) {
    /** Redact secrets for display (--show / logs). */
    function taphish_push_config_mask(array $cfg): array
    {
        foreach (['pass', 'secret_key'] as $k) {
            if (!empty($cfg[$k])) {
                $v = (string) $cfg[$k];
                $cfg[$k] = substr($v, 0, 2) . str_repeat('*', max(4, strlen($v) - 2));
            }
        }
        return $cfg;
    }
}

if (!function_exists('taphish_push_config_from_request')) {
    /**
     * Phase 3.57 — normalize a web-form / POST payload into a push-config array
     * for taphish_push_config_validate(). Trims scalar fields; includes only the
     * fields relevant to the chosen type; coerces path_style to a real bool; drops
     * a blank optional endpoint. An empty/unknown type yields just ['type'=>…] so
     * the caller can treat a blank type as "clear the destination".
     */
    function taphish_push_config_from_request(array $req): array
    {
        $type = strtolower(trim((string) ($req['type'] ?? '')));
        $cfg = ['type' => $type];
        if ($type === 'webdav') {
            $cfg['url']  = trim((string) ($req['url'] ?? ''));
            $cfg['user'] = trim((string) ($req['user'] ?? ''));
            $cfg['pass'] = (string) ($req['pass'] ?? '');
        } elseif ($type === 's3') {
            $cfg['bucket']     = trim((string) ($req['bucket'] ?? ''));
            $cfg['region']     = trim((string) ($req['region'] ?? ''));
            $cfg['access_key'] = trim((string) ($req['access_key'] ?? ''));
            $cfg['secret_key'] = (string) ($req['secret_key'] ?? '');
            $endpoint = trim((string) ($req['endpoint'] ?? ''));
            if ($endpoint !== '') {
                $cfg['endpoint'] = $endpoint;
            }
            $ps = $req['path_style'] ?? false;
            if ($ps === true || $ps === 1 || $ps === '1' || $ps === 'true' || $ps === 'on') {
                $cfg['path_style'] = true;
            }
        }
        return $cfg;
    }
}

if (!function_exists('taphish_push_merge_secret')) {
    /**
     * Phase 3.57 — carry the stored secret forward when the operator left the
     * secret field blank (editing only non-secret fields). The plaintext secret is
     * never sent back to the browser, so a blank secret on save means "keep the
     * existing one". A cross-type edit (webdav<->s3) never inherits a secret.
     */
    function taphish_push_merge_secret(array $cfg, ?array $existing): array
    {
        $type  = (string) ($cfg['type'] ?? '');
        $field = $type === 's3' ? 'secret_key' : ($type === 'webdav' ? 'pass' : '');
        if ($field === '' || $existing === null) {
            return $cfg;
        }
        if ((string) ($existing['type'] ?? '') !== $type) {
            return $cfg;
        }
        if ((string) ($cfg[$field] ?? '') === '' && (string) ($existing[$field] ?? '') !== '') {
            $cfg[$field] = (string) $existing[$field];
        }
        return $cfg;
    }
}

if (!function_exists('taphish_push_uri_encode')) {
    /** RFC-3986 per-segment encoding, slashes preserved (S3 canonical URI). */
    function taphish_push_uri_encode(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}

if (!function_exists('taphish_push_webdav_request')) {
    /** @return array{method:string,url:string,headers:string[]} */
    function taphish_push_webdav_request(array $cfg, string $remoteName): array
    {
        $url  = rtrim((string) $cfg['url'], '/') . '/' . rawurlencode($remoteName);
        $auth = 'Basic ' . base64_encode(((string) $cfg['user']) . ':' . ((string) $cfg['pass']));
        return [
            'method'  => 'PUT',
            'url'     => $url,
            'headers' => ['Authorization: ' . $auth],
        ];
    }
}

if (!function_exists('taphish_sigv4_signing_key')) {
    /** Derive the AWS SigV4 signing key (raw bytes). */
    function taphish_sigv4_signing_key(string $secret, string $dateStamp, string $region, string $service): string
    {
        $kDate    = hash_hmac('sha256', $dateStamp, 'AWS4' . $secret, true);
        $kRegion  = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }
}

if (!function_exists('taphish_sigv4_authorization')) {
    /**
     * Build the full "AWS4-HMAC-SHA256 …" Authorization header value.
     * @param array $o accessKey,secret,region,service,method,canonicalUri,query,
     *                 headers(map name=>value, names lowercased),signedHeaders,
     *                 payloadSha256,amzDate,dateStamp
     */
    function taphish_sigv4_authorization(array $o): string
    {
        $names = explode(';', $o['signedHeaders']);
        sort($names);
        $canonHeaders = '';
        foreach ($names as $n) {
            $v = preg_replace('/\s+/', ' ', trim((string) ($o['headers'][$n] ?? '')));
            $canonHeaders .= $n . ':' . $v . "\n";
        }
        $signedHeaders = implode(';', $names);

        $canonicalRequest = $o['method'] . "\n"
            . ($o['canonicalUri'] ?? '/') . "\n"
            . ($o['query'] ?? '') . "\n"
            . $canonHeaders . "\n"
            . $signedHeaders . "\n"
            . $o['payloadSha256'];

        $scope = $o['dateStamp'] . '/' . $o['region'] . '/' . $o['service'] . '/aws4_request';
        $stringToSign = "AWS4-HMAC-SHA256\n" . $o['amzDate'] . "\n" . $scope . "\n" . hash('sha256', $canonicalRequest);

        $kSigning  = taphish_sigv4_signing_key($o['secret'], $o['dateStamp'], $o['region'], $o['service']);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        return 'AWS4-HMAC-SHA256 Credential=' . $o['accessKey'] . '/' . $scope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;
    }
}

if (!function_exists('taphish_push_s3_request')) {
    /** @return array{method:string,url:string,headers:string[]} */
    function taphish_push_s3_request(array $cfg, string $remoteName, string $payloadSha256, string $amzDate): array
    {
        $region    = (string) $cfg['region'];
        $bucket    = (string) $cfg['bucket'];
        $pathStyle = !empty($cfg['path_style']);
        $key       = ltrim($remoteName, '/');

        if (!empty($cfg['endpoint'])) {
            $scheme = strncmp((string) $cfg['endpoint'], 'http://', 7) === 0 ? 'http' : 'https';
            $host   = preg_replace('#^https?://#', '', rtrim((string) $cfg['endpoint'], '/'));
        } else {
            $scheme = 'https';
            $host   = $pathStyle ? "s3.{$region}.amazonaws.com" : "{$bucket}.s3.{$region}.amazonaws.com";
        }

        $canonicalUri = $pathStyle
            ? '/' . $bucket . '/' . taphish_push_uri_encode($key)
            : '/' . taphish_push_uri_encode($key);

        $headers = [
            'host'                 => $host,
            'x-amz-content-sha256' => $payloadSha256,
            'x-amz-date'           => $amzDate,
        ];
        $auth = taphish_sigv4_authorization([
            'accessKey'     => (string) $cfg['access_key'],
            'secret'        => (string) $cfg['secret_key'],
            'region'        => $region,
            'service'       => 's3',
            'method'        => 'PUT',
            'canonicalUri'  => $canonicalUri,
            'query'         => '',
            'headers'       => $headers,
            'signedHeaders' => 'host;x-amz-content-sha256;x-amz-date',
            'payloadSha256' => $payloadSha256,
            'amzDate'       => $amzDate,
            'dateStamp'     => substr($amzDate, 0, 8),
        ]);

        return [
            'method'  => 'PUT',
            'url'     => $scheme . '://' . $host . $canonicalUri,
            'headers' => [
                'Authorization: ' . $auth,
                'x-amz-content-sha256: ' . $payloadSha256,
                'x-amz-date: ' . $amzDate,
                'Host: ' . $host,
            ],
        ];
    }
}

if (!function_exists('taphish_push_default_http')) {
    /** Real curl PUT, streaming the file from disk (bounded memory). */
    function taphish_push_default_http(array $request, string $filePath): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'status' => 0, 'error' => 'curl unavailable'];
        }
        $fh = fopen($filePath, 'rb');
        if ($fh === false) {
            return ['ok' => false, 'status' => 0, 'error' => 'cannot open file'];
        }
        $headers = $request['headers'] ?? [];
        $headers[] = 'Expect:'; // disable 100-continue (some PUT targets reject it)
        $ch = curl_init($request['url']);
        curl_setopt_array($ch, [
            CURLOPT_PUT            => true,
            CURLOPT_INFILE         => $fh,
            CURLOPT_INFILESIZE     => filesize($filePath),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 180,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body   = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err    = curl_error($ch);
        fclose($fh); // curl handle is freed on GC; curl_close() is a no-op since PHP 8.0
        $ok = $status >= 200 && $status < 300;
        return ['ok' => $ok, 'status' => $status, 'error' => $ok ? '' : ($err !== '' ? $err : 'HTTP ' . $status)];
    }
}

if (!function_exists('taphish_push_send')) {
    /** @param callable|null $http fn(array $request, string $filePath): array{ok,status,error} */
    function taphish_push_send(array $request, string $filePath, ?callable $http = null): array
    {
        if (!is_file($filePath)) {
            return ['ok' => false, 'status' => 0, 'error' => 'file not found'];
        }
        $http = $http ?? 'taphish_push_default_http';
        return $http($request, $filePath);
    }
}

// ---- tb_store config (encrypted at rest), mirroring capture_alerting/telegram ----

if (!function_exists('taphish_push_get_config')) {
    function taphish_push_get_config(\mysqli $conn): ?array
    {
        $stmt = $conn->prepare("SELECT content FROM tb_store WHERE type='backup_push' AND name='config'");
        if ($stmt === false) {
            return null;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || empty($row['content'])) {
            return null;
        }
        $content = (string) $row['content'];
        if (function_exists('secret_at_rest_is_encrypted') && secret_at_rest_is_encrypted($content)
            && function_exists('secret_at_rest_get_key') && function_exists('secret_at_rest_decrypt')) {
            $key = secret_at_rest_get_key();
            if ($key !== null) {
                $plain   = secret_at_rest_decrypt($content, $key);
                $content = is_string($plain) ? $plain : '';
            }
        }
        return taphish_push_config_deserialize($content);
    }
}

if (!function_exists('taphish_push_set_config')) {
    function taphish_push_set_config(\mysqli $conn, array $cfg): bool
    {
        $payload = taphish_push_config_serialize($cfg);
        if (function_exists('secret_at_rest_get_key') && function_exists('secret_at_rest_encrypt')) {
            $key = secret_at_rest_get_key();
            if ($key !== null) {
                $enc = secret_at_rest_encrypt($payload, $key);
                if ($enc !== null) {
                    $payload = $enc;
                }
            }
        }
        $del = $conn->prepare("DELETE FROM tb_store WHERE type='backup_push' AND name='config'");
        if ($del !== false) {
            $del->execute();
            $del->close();
        }
        $ins = $conn->prepare(
            "INSERT INTO tb_store (type, name, info, content) VALUES ('backup_push','config','Phase 3.50c off-host backup push',?)"
        );
        if ($ins === false) {
            return false;
        }
        $ins->bind_param('s', $payload);
        $ok = $ins->execute();
        $ins->close();
        return (bool) $ok;
    }
}

if (!function_exists('taphish_push_clear_config')) {
    function taphish_push_clear_config(\mysqli $conn): void
    {
        $del = $conn->prepare("DELETE FROM tb_store WHERE type='backup_push' AND name='config'");
        if ($del !== false) {
            $del->execute();
            $del->close();
        }
    }
}
