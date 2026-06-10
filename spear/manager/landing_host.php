<?php
/**
 * Phase 3.60 (P1) — External self-hosted landing hosts (FTP/FTPS push).
 *
 * Lets the operator push a built landing bundle (spear/sniperhost/cloned/<slug>/)
 * to a look-alike domain's own webspace over FTPS — so the page is served from
 * e.g. https://owa.textilcolor.ch/ with its OWN Let's-Encrypt cert, instead of
 * /p/<slug>/ on the TAPhish host. See docs/PLAN-external-landing-host.md.
 *
 * Mirrors spear/manager/backup_push.php: pure config helpers + an INJECTABLE
 * transport so the request/plan building is unit-tested without a network.
 * Connection profiles are stored in tb_store, secret sealed at rest.
 *
 * This file is intentionally DB-free except the tb_store accessors, and the
 * pure helpers are loaded by tests/Support/helpers_shim.php.
 */

if (!function_exists('landing_host_validate')) {
    /** @return array{ok:bool,errors:string[],cfg:array} */
    function landing_host_validate(array $cfg): array
    {
        $errors = [];
        $type = (string) ($cfg['type'] ?? '');
        if ($type !== 'ftp' && $type !== 'ftps') {
            $errors[] = 'type must be ftp or ftps';
        }
        foreach (['label', 'host', 'username', 'public_url_base'] as $k) {
            if (empty($cfg[$k])) {
                $errors[] = "missing {$k}";
            }
        }
        if (empty($cfg['password'])) {
            $errors[] = 'missing password';
        }
        $port = (int) ($cfg['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $errors[] = 'port must be 1..65535';
        }
        if (!empty($cfg['public_url_base']) && !preg_match('#^https?://#', (string) $cfg['public_url_base'])) {
            $errors[] = 'public_url_base must be http(s)://';
        }
        return ['ok' => $errors === [], 'errors' => $errors, 'cfg' => $cfg];
    }
}

if (!function_exists('landing_host_mask')) {
    /** Redact the password for display (UI / logs). */
    function landing_host_mask(array $cfg): array
    {
        if (!empty($cfg['password'])) {
            $v = (string) $cfg['password'];
            $cfg['password'] = substr($v, 0, 2) . str_repeat('*', max(4, strlen($v) - 2));
        }
        return $cfg;
    }
}

if (!function_exists('landing_host_normalize_base_path')) {
    /** Normalize the remote base path: drop surrounding slashes; '' or '.' => ''. */
    function landing_host_normalize_base_path(string $p): string
    {
        $p = trim($p);
        if ($p === '.' || $p === './' || $p === '/') {
            return '';
        }
        return trim($p, '/');
    }
}

if (!function_exists('landing_host_from_request')) {
    /**
     * Normalize a web-form / POST payload into a profile config. Keeps a stable
     * `id` (generated if absent) so saves upsert in place. Port defaults to 21.
     */
    function landing_host_from_request(array $req): array
    {
        $type = strtolower(trim((string) ($req['type'] ?? 'ftps')));
        if ($type !== 'ftp' && $type !== 'ftps') {
            $type = 'ftps';
        }
        $id = preg_replace('/[^A-Za-z0-9_-]/', '', (string) ($req['id'] ?? '')) ?? '';
        if ($id === '') {
            $id = 'lh_' . substr(bin2hex(random_bytes(6)), 0, 12);
        }
        $port = (int) ($req['port'] ?? 21);
        if ($port < 1 || $port > 65535) {
            $port = 21;
        }
        return [
            'id'               => $id,
            'label'            => trim((string) ($req['label'] ?? '')),
            'type'             => $type,
            'host'             => trim((string) ($req['host'] ?? '')),
            'port'             => $port,
            'username'         => trim((string) ($req['username'] ?? '')),
            'password'         => (string) ($req['password'] ?? ''),
            'remote_base_path' => landing_host_normalize_base_path((string) ($req['remote_base_path'] ?? '')),
            'public_url_base'  => rtrim(trim((string) ($req['public_url_base'] ?? '')), '/') . '/',
        ];
    }
}

if (!function_exists('landing_host_merge_secret')) {
    /**
     * Carry the stored password forward when the operator left the field blank
     * (editing only non-secret fields). The plaintext password is never sent
     * back to the browser, so blank-on-save means "keep the existing one".
     */
    function landing_host_merge_secret(array $cfg, ?array $existing): array
    {
        if ($existing === null) {
            return $cfg;
        }
        if ((string) ($cfg['password'] ?? '') === '' && (string) ($existing['password'] ?? '') !== '') {
            $cfg['password'] = (string) $existing['password'];
        }
        return $cfg;
    }
}

if (!function_exists('landing_host_public_url')) {
    /** Public URL of a pushed landing slug, e.g. https://owa.example.ch/<slug>/. */
    function landing_host_public_url(array $cfg, string $slug): string
    {
        $base = rtrim((string) ($cfg['public_url_base'] ?? ''), '/');
        $slug = trim($slug, '/');
        return $base . '/' . $slug . '/';
    }
}

if (!function_exists('landing_host_remote_root')) {
    /** Remote dir a slug is pushed into, relative to the FTP login dir. */
    function landing_host_remote_root(array $cfg, string $slug): string
    {
        $base = landing_host_normalize_base_path((string) ($cfg['remote_base_path'] ?? ''));
        $slug = trim($slug, '/');
        return ($base !== '' ? $base . '/' : '') . $slug;
    }
}

if (!function_exists('landing_host_ftp_url')) {
    /**
     * Build the cURL FTP URL for a remote path. Always ftp:// (explicit TLS is
     * a transport option, not a scheme); the uploader enables TLS for type=ftps.
     * Each path segment is rawurlencoded; slashes preserved.
     */
    function landing_host_ftp_url(array $cfg, string $remotePath): string
    {
        $host = trim((string) ($cfg['host'] ?? ''));
        $port = (int) ($cfg['port'] ?? 21);
        $remotePath = ltrim($remotePath, '/');
        $enc = implode('/', array_map('rawurlencode', explode('/', $remotePath)));
        return 'ftp://' . $host . ':' . $port . '/' . $enc;
    }
}

if (!function_exists('landing_host_map_remote')) {
    /**
     * Pure path mapper: given absolute local file paths under $localDir and a
     * remote root, return [['local'=>abs,'remote'=>relpath], …] in input order.
     * Mixed slashes / a trailing slash on localDir are tolerated.
     *
     * @param string[] $files
     * @return array<int,array{local:string,remote:string}>
     */
    function landing_host_map_remote(array $files, string $localDir, string $remoteRoot): array
    {
        $localDir  = rtrim(str_replace('\\', '/', $localDir), '/');
        $remoteRoot = trim($remoteRoot, '/');
        $out = [];
        foreach ($files as $f) {
            $norm = str_replace('\\', '/', (string) $f);
            $rel  = ($localDir !== '' && strncmp($norm, $localDir . '/', strlen($localDir) + 1) === 0)
                ? substr($norm, strlen($localDir) + 1)
                : ltrim($norm, '/');
            $remote = ($remoteRoot !== '' ? $remoteRoot . '/' : '') . $rel;
            $out[] = ['local' => (string) $f, 'remote' => $remote];
        }
        return $out;
    }
}

if (!function_exists('landing_host_list_files')) {
    /** Recursively list regular files under a directory (absolute paths). */
    function landing_host_list_files(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $out = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile()) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);
        return $out;
    }
}

if (!function_exists('landing_host_default_upload')) {
    /**
     * Real cURL FTP(S) upload of one file, creating missing remote dirs. For
     * type=ftps, explicit TLS on both control + data channels is required.
     *
     * @return array{ok:bool,error:string}
     */
    function landing_host_default_upload(array $cfg, string $localFile, string $remotePath): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => 'curl unavailable'];
        }
        $fh = @fopen($localFile, 'rb');
        if ($fh === false) {
            return ['ok' => false, 'error' => 'cannot open ' . $localFile];
        }
        $ch = curl_init(landing_host_ftp_url($cfg, $remotePath));
        $opts = [
            CURLOPT_UPLOAD            => true,
            CURLOPT_INFILE           => $fh,
            CURLOPT_INFILESIZE       => filesize($localFile),
            CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR,
            CURLOPT_USERPWD          => ((string) ($cfg['username'] ?? '')) . ':' . ((string) ($cfg['password'] ?? '')),
            CURLOPT_FTP_USE_EPSV     => true,
            CURLOPT_RETURNTRANSFER   => true,
            CURLOPT_TIMEOUT          => 120,
            CURLOPT_CONNECTTIMEOUT   => 20,
        ];
        if ((string) ($cfg['type'] ?? 'ftps') === 'ftps') {
            // Explicit TLS (AUTH TLS) on control + data, verify the cert.
            $opts[CURLOPT_USE_SSL]        = CURLUSESSL_ALL;
            $opts[CURLOPT_SSL_VERIFYPEER] = true;
            $opts[CURLOPT_SSL_VERIFYHOST] = 2;
        }
        curl_setopt_array($ch, $opts);
        curl_exec($ch);
        $errno = curl_errno($ch);
        $err   = curl_error($ch);
        fclose($fh);
        if ($errno !== 0) {
            return ['ok' => false, 'error' => 'cURL ' . $errno . ': ' . $err];
        }
        return ['ok' => true, 'error' => ''];
    }
}

if (!function_exists('landing_host_push_dir')) {
    /**
     * Push every file under $localDir to the profile's remote root for $slug.
     * The per-file uploader is injectable (tests pass a stub; production uses
     * landing_host_default_upload). Stops on the first failure.
     *
     * @param callable|null $uploader fn(array $cfg, string $localFile, string $remotePath): array{ok,error}
     * @return array{ok:bool,uploaded:int,total:int,public_url:string,error:string}
     */
    function landing_host_push_dir(array $cfg, string $slug, string $localDir, ?callable $uploader = null): array
    {
        $uploader = $uploader ?? 'landing_host_default_upload';
        $files = landing_host_list_files($localDir);
        $plan  = landing_host_map_remote($files, $localDir, landing_host_remote_root($cfg, $slug));
        $public = landing_host_public_url($cfg, $slug);
        if ($plan === []) {
            return ['ok' => false, 'uploaded' => 0, 'total' => 0, 'public_url' => $public, 'error' => 'no files to upload'];
        }
        $done = 0;
        foreach ($plan as $item) {
            $r = $uploader($cfg, $item['local'], $item['remote']);
            if (empty($r['ok'])) {
                return [
                    'ok' => false, 'uploaded' => $done, 'total' => count($plan),
                    'public_url' => $public,
                    'error' => 'upload failed for ' . $item['remote'] . ': ' . (string) ($r['error'] ?? 'unknown'),
                ];
            }
            $done++;
        }
        return ['ok' => true, 'uploaded' => $done, 'total' => count($plan), 'public_url' => $public, 'error' => ''];
    }
}

// ---- tb_store: list of profiles, encrypted at rest (mirrors backup_push) ----

if (!function_exists('landing_host_get_all')) {
    /** @return array<int,array> all configured profiles (secrets included). */
    function landing_host_get_all(\mysqli $conn): array
    {
        $stmt = $conn->prepare("SELECT content FROM tb_store WHERE type='landing_host' AND name='profiles'");
        if ($stmt === false) {
            return [];
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row || empty($row['content'])) {
            return [];
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
        $d = json_decode($content, true);
        return is_array($d) ? array_values(array_filter($d, 'is_array')) : [];
    }
}

if (!function_exists('landing_host_get')) {
    function landing_host_get(\mysqli $conn, string $id): ?array
    {
        foreach (landing_host_get_all($conn) as $p) {
            if ((string) ($p['id'] ?? '') === $id) {
                return $p;
            }
        }
        return null;
    }
}

if (!function_exists('landing_host_store_all')) {
    /** Persist the whole profile list (sealed). */
    function landing_host_store_all(\mysqli $conn, array $profiles): bool
    {
        $payload = (string) json_encode(array_values($profiles));
        if (function_exists('secret_at_rest_get_key') && function_exists('secret_at_rest_encrypt')) {
            $key = secret_at_rest_get_key();
            if ($key !== null) {
                $enc = secret_at_rest_encrypt($payload, $key);
                if ($enc !== null) {
                    $payload = $enc;
                }
            }
        }
        $del = $conn->prepare("DELETE FROM tb_store WHERE type='landing_host' AND name='profiles'");
        if ($del !== false) { $del->execute(); $del->close(); }
        $ins = $conn->prepare(
            "INSERT INTO tb_store (type, name, info, content) VALUES ('landing_host','profiles','Phase 3.60 external landing hosts',?)"
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

if (!function_exists('landing_host_save')) {
    /** Upsert a profile by id. Returns false on a storage error. */
    function landing_host_save(\mysqli $conn, array $cfg): bool
    {
        $id = (string) ($cfg['id'] ?? '');
        if ($id === '') {
            return false;
        }
        $all = landing_host_get_all($conn);
        $replaced = false;
        foreach ($all as $i => $p) {
            if ((string) ($p['id'] ?? '') === $id) {
                $all[$i] = $cfg;
                $replaced = true;
                break;
            }
        }
        if (!$replaced) {
            $all[] = $cfg;
        }
        return landing_host_store_all($conn, $all);
    }
}

if (!function_exists('landing_host_public_hosts')) {
    /**
     * The host names of every configured profile's public_url_base — fed to the
     * launch landing-probe allow-list (taphish_landing_url_is_probeable) so a
     * self-hosted look-alike landing isn't rejected as off-host SSRF.
     *
     * @return string[]
     */
    function landing_host_public_hosts(\mysqli $conn): array
    {
        $hosts = [];
        foreach (landing_host_get_all($conn) as $p) {
            $h = parse_url((string) ($p['public_url_base'] ?? ''), PHP_URL_HOST);
            if (is_string($h) && $h !== '') {
                $hosts[] = strtolower($h);
            }
        }
        return array_values(array_unique($hosts));
    }
}

if (!function_exists('landing_host_delete')) {
    function landing_host_delete(\mysqli $conn, string $id): bool
    {
        $all = landing_host_get_all($conn);
        $kept = array_values(array_filter($all, static fn($p) => (string) ($p['id'] ?? '') !== $id));
        return landing_host_store_all($conn, $kept);
    }
}
