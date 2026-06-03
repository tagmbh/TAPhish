<?php
/**
 * Phase 3.43a: engagement metadata — the spine of the Quick-Start
 * Wizard. An engagement scopes a phishing campaign to a named target
 * organisation, a time window, and an authorised list of email domains
 * the operator is allowed to phish. Subsequent reports + multi-operator
 * RBAC (Phases 3.45 / 3.46) scope through this row.
 *
 * Stored in tb_core_engagement. PII (recipient lists) stays in
 * tb_core_mailcamp_user_group; this table holds engagement metadata only.
 */

if (!function_exists('taphish_engagement_status_list')) {
    function taphish_engagement_status_list(): array
    {
        return ['draft', 'live', 'completed', 'cancelled'];
    }
}

if (!function_exists('taphish_engagement_slugify')) {
    /**
     * Produce a stable, URL-safe slug from a free-text engagement name.
     * Used as the natural-key column so the operator can refer to an
     * engagement by slug in URLs / report filenames.
     */
    function taphish_engagement_slugify(string $name): string
    {
        $name = trim($name);
        $name = strtolower($name);
        // Replace anything that isn't a-z 0-9 with a single dash.
        $slug = preg_replace('/[^a-z0-9]+/', '-', $name);
        $slug = trim((string) $slug, '-');
        if ($slug === '') {
            return '';
        }
        // Cap length so it fits VARCHAR(96) with margin for a numeric
        // disambiguator (-2, -3, ...) appended on collision elsewhere.
        if (strlen($slug) > 80) {
            $slug = substr($slug, 0, 80);
            $slug = rtrim($slug, '-');
        }
        return $slug;
    }
}

if (!function_exists('taphish_engagement_parse_scope_allowlist')) {
    /**
     * Accept whatever the operator pasted in the "authorised domains"
     * textarea — commas, newlines, whitespace, leading @, mixed case,
     * trailing dots — and produce a clean, deduped, sorted array of
     * lowercase domain names. Returns [] if nothing valid was provided.
     *
     * Wildcard prefixes (*.acme.com) are normalised to just acme.com on
     * the principle that the allowlist is "this base domain + subs". A
     * literal "*" alone is rejected to avoid an accidental "phish anyone".
     */
    function taphish_engagement_parse_scope_allowlist(string $raw): array
    {
        $tokens = preg_split('/[\s,;]+/', $raw) ?: [];
        $out = [];
        foreach ($tokens as $t) {
            $t = strtolower(trim($t));
            if ($t === '' || $t === '*') {
                continue;
            }
            $t = ltrim($t, '@');
            $t = preg_replace('/^\*\.+/', '', $t) ?? $t;
            $t = rtrim($t, '.');
            // RFC-ish: at least one dot, each label 1-63 chars, only
            // a-z 0-9 hyphen, no leading/trailing hyphen per label.
            if (!preg_match('/^([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $t)) {
                continue;
            }
            $out[$t] = true;
        }
        $out = array_keys($out);
        sort($out);
        return $out;
    }
}

if (!function_exists('taphish_engagement_domain_in_scope')) {
    /**
     * True if $email's domain is covered by $allowlist. The match is
     * either an exact domain hit or a suffix hit (so target acme.com
     * also covers vendors at hr.acme.com).
     */
    function taphish_engagement_domain_in_scope(string $email, array $allowlist): bool
    {
        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = strtolower(substr($email, $at + 1));
        $domain = rtrim($domain, '.');
        if ($domain === '') {
            return false;
        }
        foreach ($allowlist as $entry) {
            $entry = strtolower((string) $entry);
            if ($entry === '') {
                continue;
            }
            if ($domain === $entry) {
                return true;
            }
            if (substr($domain, -strlen($entry) - 1) === '.' . $entry) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('taphish_engagement_validate_input')) {
    /**
     * Validate the Step-1 wizard payload. Returns ['ok' => bool, 'errors'
     * => string[], 'normalized' => array] so the caller can both render
     * field-level errors and persist the cleaned record. Pure — no DB.
     *
     * Accepted shape:
     *   name (string, required, 3..160 chars)
     *   target_org (string, optional, <=160)
     *   start_at (string, required, ISO-8601 'YYYY-MM-DD' or 'YYYY-MM-DDTHH:MM' )
     *   end_at (string, required, must be > start_at)
     *   scope_allowlist (string, required, at least one valid domain)
     *   notes (string, optional, <=2000)
     */
    function taphish_engagement_validate_input(array $payload): array
    {
        $errors = [];
        $name = trim((string) ($payload['name'] ?? ''));
        $org  = trim((string) ($payload['target_org'] ?? ''));
        $startRaw = trim((string) ($payload['start_at'] ?? ''));
        $endRaw   = trim((string) ($payload['end_at'] ?? ''));
        $scopeRaw = (string) ($payload['scope_allowlist'] ?? '');
        $notes = trim((string) ($payload['notes'] ?? ''));

        if (strlen($name) < 3 || strlen($name) > 160) {
            $errors['name'] = 'Engagement name must be 3–160 characters.';
        }
        if ($org !== '' && strlen($org) > 160) {
            $errors['target_org'] = 'Target organisation name is too long.';
        }
        if (strlen($notes) > 2000) {
            $errors['notes'] = 'Notes are too long (2000 char max).';
        }

        $startTs = $startRaw !== '' ? taphish_engagement_parse_datetime($startRaw) : null;
        $endTs   = $endRaw   !== '' ? taphish_engagement_parse_datetime($endRaw)   : null;
        if ($startTs === null) {
            $errors['start_at'] = 'Start date is required (YYYY-MM-DD or YYYY-MM-DDTHH:MM).';
        }
        if ($endTs === null) {
            $errors['end_at'] = 'End date is required.';
        }
        if ($startTs !== null && $endTs !== null && $endTs <= $startTs) {
            $errors['end_at'] = 'End must be after start.';
        }

        $allowlist = taphish_engagement_parse_scope_allowlist($scopeRaw);
        if (count($allowlist) === 0) {
            $errors['scope_allowlist'] = 'At least one authorised email domain is required.';
        }

        $slug = taphish_engagement_slugify($name);
        if ($slug === '' && !isset($errors['name'])) {
            $errors['name'] = 'Engagement name must contain at least one letter or digit.';
        }

        return [
            'ok' => count($errors) === 0,
            'errors' => $errors,
            'normalized' => [
                'name' => $name,
                'slug' => $slug,
                'target_org' => $org,
                'start_at' => $startTs !== null ? gmdate('Y-m-d H:i:s', $startTs) : null,
                'end_at'   => $endTs   !== null ? gmdate('Y-m-d H:i:s', $endTs)   : null,
                'scope_allowlist' => $allowlist,
                'notes' => $notes,
            ],
        ];
    }
}

if (!function_exists('taphish_engagement_parse_datetime')) {
    /**
     * Accept YYYY-MM-DD or YYYY-MM-DDTHH:MM (browser <input type=date> and
     * <input type=datetime-local> both produce one of these). Returns a
     * UTC timestamp or null on parse failure. Pure — no timezone DB.
     */
    function taphish_engagement_parse_datetime(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            $raw .= 'T00:00';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?$/', $raw)) {
            return null;
        }
        $ts = strtotime($raw . ' UTC');
        return $ts === false ? null : $ts;
    }
}

if (!function_exists('taphish_engagement_ensure_schema')) {
    function taphish_engagement_ensure_schema(\mysqli $conn): void
    {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_core_engagement'"
        );
        if ($stmt === false) {
            return;
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_row();
        $stmt->close();
        if ($row && (int) $row[0] > 0) {
            return;
        }
        @$conn->query(
            "CREATE TABLE tb_core_engagement (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                slug VARCHAR(96) NOT NULL,
                name VARCHAR(160) NOT NULL,
                target_org VARCHAR(160) NULL,
                start_at DATETIME NOT NULL,
                end_at DATETIME NOT NULL,
                scope_allowlist TEXT NOT NULL,
                notes TEXT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'draft',
                created_by VARCHAR(64) NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_slug (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

if (!function_exists('taphish_engagement_unique_slug')) {
    /**
     * Given a base slug, append -2 / -3 / ... until a free slot exists.
     * DB-bound — needs a connection. Pure check on slug shape is via
     * taphish_engagement_slugify().
     */
    function taphish_engagement_unique_slug(\mysqli $conn, string $base): string
    {
        if ($base === '') {
            return '';
        }
        $candidate = $base;
        $i = 2;
        while (true) {
            $stmt = $conn->prepare("SELECT COUNT(*) FROM tb_core_engagement WHERE slug = ?");
            if ($stmt === false) {
                return $candidate;
            }
            $stmt->bind_param('s', $candidate);
            $stmt->execute();
            $n = (int) $stmt->get_result()->fetch_row()[0];
            $stmt->close();
            if ($n === 0) {
                return $candidate;
            }
            $candidate = $base . '-' . $i;
            $i++;
            if ($i > 9999) {
                return $candidate; // give up — pathological case
            }
        }
    }
}

if (!function_exists('taphish_engagement_insert')) {
    function taphish_engagement_insert(\mysqli $conn, array $normalized, string $createdBy): ?int
    {
        $slug = taphish_engagement_unique_slug($conn, $normalized['slug']);
        $scopeJson = json_encode(array_values($normalized['scope_allowlist']));
        $org = $normalized['target_org'] !== '' ? $normalized['target_org'] : null;
        $notes = $normalized['notes'] !== '' ? $normalized['notes'] : null;

        $stmt = $conn->prepare(
            "INSERT INTO tb_core_engagement
                (slug, name, target_org, start_at, end_at, scope_allowlist, notes, status, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', ?)"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param(
            'ssssssss',
            $slug,
            $normalized['name'],
            $org,
            $normalized['start_at'],
            $normalized['end_at'],
            $scopeJson,
            $notes,
            $createdBy
        );
        if (!$stmt->execute()) {
            $stmt->close();
            return null;
        }
        $id = $conn->insert_id;
        $stmt->close();
        return $id ?: null;
    }
}

if (!function_exists('taphish_engagement_list')) {
    function taphish_engagement_list(\mysqli $conn, int $limit = 50): array
    {
        $stmt = $conn->prepare(
            "SELECT id, slug, name, target_org, start_at, end_at, scope_allowlist, status, created_at, wizard_step
             FROM tb_core_engagement
             ORDER BY created_at DESC
             LIMIT ?"
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $limit);
        $stmt->execute();
        $res = $stmt->get_result();
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $r['scope_allowlist'] = json_decode((string) $r['scope_allowlist'], true) ?: [];
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('taphish_engagement_ensure_campaign_fk_column')) {
    /**
     * Phase 3.45b: add a nullable `engagement_id` column to
     * `tb_core_mailcamp_list` so a campaign can be scoped to an
     * engagement. Nullable + indexed so existing campaigns stay
     * untouched and EngagementView lookups are O(log n).
     */
    function taphish_engagement_ensure_campaign_fk_column(\mysqli $conn): void
    {
        $stmt = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'tb_core_mailcamp_list'
               AND COLUMN_NAME = 'engagement_id'"
        );
        if ($stmt === false) {
            return;
        }
        $stmt->execute();
        $present = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();
        if ($present > 0) {
            return;
        }
        @$conn->query("ALTER TABLE tb_core_mailcamp_list ADD COLUMN engagement_id INT UNSIGNED NULL DEFAULT NULL");
        @$conn->query("CREATE INDEX idx_mailcamp_engagement ON tb_core_mailcamp_list(engagement_id)");
    }
}

if (!function_exists('taphish_engagement_validate_transition')) {
    /**
     * Phase 3.45b: pure-side validator for engagement status changes.
     * Allowed destination states come from
     * `taphish_engagement_status_list()`; the actual CAS UPDATE lives
     * in `taphish_engagement_transition_status()`.
     */
    function taphish_engagement_validate_transition(string $from, string $to): bool
    {
        $valid = taphish_engagement_status_list();
        return in_array($to, $valid, true);
    }
}

if (!function_exists('taphish_engagement_transition_status')) {
    /**
     * Phase 3.45b: compare-and-swap engagement status update. The
     * WHERE-status clause means a double-click Launch can't double-
     * launch — only the first UPDATE finds the matching row.
     */
    function taphish_engagement_transition_status(\mysqli $conn, int $id, string $from, string $to): bool
    {
        if (!taphish_engagement_validate_transition($from, $to)) {
            return false;
        }
        $stmt = $conn->prepare("UPDATE tb_core_engagement SET status = ? WHERE id = ? AND status = ?");
        if ($stmt === false) {
            return false;
        }
        $stmt->bind_param('sis', $to, $id, $from);
        $ok = $stmt->execute();
        $changed = $stmt->affected_rows > 0;
        $stmt->close();
        return $ok && $changed;
    }
}

if (!function_exists('taphish_engagement_get_by_id')) {
    function taphish_engagement_get_by_id(\mysqli $conn, int $id): ?array
    {
        $stmt = $conn->prepare(
            "SELECT id, slug, name, target_org, start_at, end_at, scope_allowlist, notes, status, created_by, created_at, wizard_step, wizard_state
             FROM tb_core_engagement WHERE id = ?"
        );
        if ($stmt === false) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return null;
        }
        $row['scope_allowlist'] = json_decode((string) $row['scope_allowlist'], true) ?: [];
        return $row;
    }
}

if (!function_exists('taphish_engagement_campaigns')) {
    function taphish_engagement_campaigns(\mysqli $conn, int $id): array
    {
        $stmt = $conn->prepare(
            "SELECT campaign_id, campaign_name, scheduled_time, camp_status, date
             FROM tb_core_mailcamp_list
             WHERE engagement_id = ?
             ORDER BY date DESC"
        );
        if ($stmt === false) {
            return [];
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        $out = [];
        while ($r = $res->fetch_assoc()) {
            $out[] = $r;
        }
        $stmt->close();
        return $out;
    }
}

if (!function_exists('taphish_engagement_delete')) {
    /**
     * Delete an engagement. Linked campaigns are NOT deleted — their
     * engagement_id FK is set back to NULL so the campaigns survive,
     * just unlinked (deleting an engagement should never silently
     * destroy campaign data). The same is done for any per-clone
     * metadata rows that reference this engagement.
     *
     * Returns the number of campaigns that were unlinked on success,
     * or null if the engagement row didn't exist / the delete failed.
     */
    function taphish_engagement_delete(\mysqli $conn, int $id): ?int
    {
        if ($id <= 0) {
            return null;
        }
        // Unlink campaigns first so we never orphan a FK if the engagement
        // delete succeeds but a later statement fails.
        $unlinked = 0;
        $stmt = $conn->prepare(
            "UPDATE tb_core_mailcamp_list SET engagement_id = NULL WHERE engagement_id = ?"
        );
        if ($stmt !== false) {
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $unlinked = $stmt->affected_rows;
            $stmt->close();
        }
        // Unlink per-clone metadata if that table exists (Phase 3.52).
        $checkClone = $conn->prepare(
            "SELECT COUNT(*) FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tb_data_clone_meta'"
        );
        if ($checkClone !== false) {
            $checkClone->execute();
            $row = $checkClone->get_result()->fetch_row();
            $checkClone->close();
            if ($row && (int) $row[0] > 0) {
                $c = $conn->prepare("UPDATE tb_data_clone_meta SET engagement_id = NULL WHERE engagement_id = ?");
                if ($c !== false) {
                    $c->bind_param('i', $id);
                    $c->execute();
                    $c->close();
                }
            }
        }
        // Delete the engagement row.
        $del = $conn->prepare("DELETE FROM tb_core_engagement WHERE id = ?");
        if ($del === false) {
            return null;
        }
        $del->bind_param('i', $id);
        $ok = $del->execute();
        $deleted = $del->affected_rows;
        $del->close();
        if (!$ok || $deleted === 0) {
            return null;
        }
        return $unlinked;
    }
}

if (!function_exists('taphish_engagement_ensure_wizard_columns')) {
    /**
     * Phase 3.56: add wizard_step + wizard_state so the QuickStart
     * wizard is resumable. Idempotent boot-time migration (mirrors the
     * Phase 3.45a/b pattern).
     */
    function taphish_engagement_ensure_wizard_columns(\mysqli $conn): void
    {
        $cols = [
            ['wizard_step',  'TINYINT NOT NULL DEFAULT 1'],
            ['wizard_state', 'MEDIUMTEXT NULL'],
        ];
        foreach ($cols as [$col, $type]) {
            $stmt = $conn->prepare(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = DATABASE()
                    AND TABLE_NAME = 'tb_core_engagement'
                    AND COLUMN_NAME = ?"
            );
            if ($stmt === false) continue;
            $stmt->bind_param('s', $col);
            $stmt->execute();
            $present = (int) $stmt->get_result()->fetch_row()[0];
            $stmt->close();
            if ($present > 0) continue;
            @$conn->query("ALTER TABLE tb_core_engagement ADD COLUMN `{$col}` {$type}");
        }
    }
}

if (!function_exists('taphish_wizard_state_normalize')) {
    /**
     * Phase 3.56: reduce an arbitrary wizard-state blob to a small,
     * whitelisted shape we're willing to persist. NO secrets — the
     * DKIM private key is shown once and never stored; recipient PII
     * stays in its encrypted table. We keep only the non-sensitive
     * inputs needed to restore the wizard's position.
     *
     * @return array{step:int, target_domain:string, dkim_selector:string, landing_slug:string, pretext_id:int}
     */
    function taphish_wizard_state_normalize(array $in): array
    {
        $step = (int) ($in['step'] ?? 1);
        if ($step < 1) $step = 1;
        if ($step > 7) $step = 7;
        return [
            'step'          => $step,
            'target_domain' => substr(trim((string) ($in['target_domain'] ?? '')), 0, 253),
            'dkim_selector' => substr(preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($in['dkim_selector'] ?? ''))) ?? '', 0, 16),
            'landing_slug'  => substr(preg_replace('/[^a-z0-9-]/', '', strtolower((string) ($in['landing_slug'] ?? ''))) ?? '', 0, 61),
            'pretext_id'    => max(0, (int) ($in['pretext_id'] ?? 0)),
        ];
    }
}

if (!function_exists('taphish_wizard_state_encode')) {
    /**
     * Normalize + JSON-encode for storage in wizard_state.
     */
    function taphish_wizard_state_encode(array $in): string
    {
        return (string) json_encode(taphish_wizard_state_normalize($in), JSON_UNESCAPED_SLASHES);
    }
}

if (!function_exists('taphish_engagement_set_wizard_progress')) {
    /**
     * Persist the wizard's current step + normalized state JSON onto
     * the engagement. Clamps step to 1..7. Returns true on success.
     */
    function taphish_engagement_set_wizard_progress(\mysqli $conn, int $id, int $step, array $state): bool
    {
        if ($id <= 0) return false;
        if ($step < 1) $step = 1;
        if ($step > 7) $step = 7;
        $json = taphish_wizard_state_encode(['step' => $step] + $state);
        $stmt = $conn->prepare("UPDATE tb_core_engagement SET wizard_step = ?, wizard_state = ? WHERE id = ?");
        if ($stmt === false) return false;
        $stmt->bind_param('isi', $step, $json, $id);
        $ok = $stmt->execute();
        $changed = $stmt->affected_rows >= 0;
        $stmt->close();
        return $ok && $changed;
    }
}

if (!function_exists('taphish_wizard_resume_payload')) {
    /**
     * Phase 3.56: shape an engagement row into the small payload QuickStart
     * needs to resume the wizard — id, clamped step (1..7), and the raw
     * (already-normalized) state JSON. Pure; a null/empty row yields a
     * fresh-start payload so the page renders Step 1.
     *
     * @return array{id:int, step:int, state:string}
     */
    function taphish_wizard_resume_payload(?array $eng): array
    {
        if (!$eng || !isset($eng['id'])) {
            return ['id' => 0, 'step' => 1, 'state' => '{}'];
        }
        $step = (int) ($eng['wizard_step'] ?? 1);
        if ($step < 1) $step = 1;
        if ($step > 7) $step = 7;
        $ws = $eng['wizard_state'] ?? '';
        return [
            'id'    => (int) $eng['id'],
            'step'  => $step,
            'state' => (is_string($ws) && $ws !== '') ? $ws : '{}',
        ];
    }
}
