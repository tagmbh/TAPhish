<?php
/**
 * Phase 3.41: pre-engagement homoglyph + typo domain generator.
 *
 * Given a target domain (e.g. "target.com"), return a ranked list of
 * visually-similar look-alike domains the operator can register for
 * the engagement. Three generators feed the candidate set:
 *
 *  1. Homoglyph substitution — replace letters with visually identical
 *     glyphs (cyrillic "а" for latin "a", greek "ο" for latin "o").
 *  2. Typo neighbours — qwerty-adjacent key swaps + character drop +
 *     character insertion.
 *  3. TLD swap — .com -> .co/.io/.net/.org/.com.co/.io.
 *
 * Pure: no DNS, no I/O, no network. The "score" is a heuristic
 * confusability (0..100) — IDN-encoded cyrillic in-place swaps score
 * highest, TLD swaps lowest. Operator decides which candidate to
 * actually buy.
 */

if (!function_exists('taphish_homoglyph_map')) {
    /**
     * Lower-case latin letter -> array of visually-similar unicode
     * code points. Conservative list, focused on the high-confusion
     * cyrillic + greek lookalikes.
     */
    function taphish_homoglyph_map(): array
    {
        return [
            'a' => ['а'],          // cyrillic "а" U+0430
            'b' => ['Ь'],          // cyrillic "Ь" U+042C (lowercase context)
            'c' => ['с'],          // cyrillic "с" U+0441
            'd' => ['ԁ'],          // cyrillic "ԁ" U+0501
            'e' => ['е'],          // cyrillic "е" U+0435
            'g' => ['ɡ'],          // ipa "ɡ"  U+0261
            'h' => ['һ'],          // cyrillic "һ" U+04BB
            'i' => ['і', 'l'],     // cyrillic "і" U+0456 ; lowercase "l" rendered close to i
            'j' => ['ј'],          // cyrillic "ј" U+0458
            'k' => ['ӄ'],          // cyrillic "ӄ" U+04C4
            'l' => ['ӏ', '1'],     // cyrillic "ӏ" U+04CF, digit "1"
            'm' => ['rn'],         // "rn" digraph approximates "m"
            'o' => ['о', '0'],     // cyrillic "о" U+043E ; digit "0"
            'p' => ['р'],          // cyrillic "р" U+0440
            'q' => ['ԛ'],          // cyrillic "ԛ" U+051B
            's' => ['ѕ'],          // cyrillic "ѕ" U+0455
            't' => ['τ'],          // greek "τ" U+03C4
            'u' => ['υ'],          // greek "υ" U+03C5
            'v' => ['ν'],          // greek "ν" U+03BD
            'w' => ['ѡ'],          // cyrillic "ѡ" U+0461
            'x' => ['х'],          // cyrillic "х" U+0445
            'y' => ['у'],          // cyrillic "у" U+0443
            'z' => ['ʐ'],          // ipa "ʐ"  U+0290
        ];
    }
}

if (!function_exists('taphish_qwerty_neighbours')) {
    /**
     * Lower-case latin letter -> array of qwerty-adjacent letters
     * (typos that occur in normal speed typing). Used for the
     * typo-neighbour generator. Only letters that occur in domain
     * names appear here.
     */
    function taphish_qwerty_neighbours(): array
    {
        return [
            'a' => ['s','q','z','w'],
            'b' => ['v','n','g','h'],
            'c' => ['x','v','d','f'],
            'd' => ['s','f','e','r','c','x'],
            'e' => ['w','r','d','s','f'],
            'f' => ['d','g','r','t','c','v'],
            'g' => ['f','h','t','y','v','b'],
            'h' => ['g','j','y','u','b','n'],
            'i' => ['u','o','k','j','l'],
            'j' => ['h','k','u','i','n','m'],
            'k' => ['j','l','i','o','m'],
            'l' => ['k','o','p'],
            'm' => ['n','j','k'],
            'n' => ['b','m','h','j'],
            'o' => ['i','p','k','l'],
            'p' => ['o','l'],
            'q' => ['w','a'],
            'r' => ['e','t','d','f'],
            's' => ['a','d','w','e','z','x'],
            't' => ['r','y','f','g'],
            'u' => ['y','i','h','j'],
            'v' => ['c','b','f','g'],
            'w' => ['q','e','a','s'],
            'x' => ['z','c','s','d'],
            'y' => ['t','u','g','h'],
            'z' => ['a','s','x'],
        ];
    }
}

if (!function_exists('taphish_split_domain')) {
    /**
     * Split "sub.target.com" into ['name' => 'sub.target', 'tld' => 'com'].
     * Multi-part TLDs (.co.uk, .com.au, …) collapse to the LAST label
     * because we only intend to swap the TLD itself, never play with
     * the eTLD+1 split. Empty/invalid input returns empty fields.
     */
    function taphish_split_domain(string $domain): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '' || !str_contains($domain, '.')) {
            return ['name' => $domain, 'tld' => ''];
        }
        $dot = strrpos($domain, '.');
        return [
            'name' => substr($domain, 0, $dot),
            'tld'  => substr($domain, $dot + 1),
        ];
    }
}

if (!function_exists('taphish_homoglyph_candidates')) {
    /**
     * Generate ranked look-alike candidates for $domain.
     * Returns an array of ['domain', 'kind', 'score'] entries, where
     * kind is one of 'homoglyph', 'typo', 'tld', 'insert', and score
     * is a heuristic 0..100 confusability rating (higher = more
     * convincing at first glance).
     *
     * Capped at $limit total entries. Duplicates and the input
     * domain itself are filtered out.
     */
    function taphish_homoglyph_candidates(string $domain, int $limit = 60): array
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') return [];
        $parts = taphish_split_domain($domain);
        $name  = $parts['name'];
        $tld   = $parts['tld'];
        if ($name === '') return [];

        $out = [];
        $seen = [strtolower($domain) => true];

        $push = function (string $cand_name, string $cand_tld, string $kind, int $score) use (&$out, &$seen) {
            $full = $cand_name . ($cand_tld !== '' ? '.' . $cand_tld : '');
            $key = strtolower($full);
            if (isset($seen[$key])) return;
            $seen[$key] = true;
            $out[] = ['domain' => $full, 'kind' => $kind, 'score' => $score];
        };

        // ---- Homoglyph in-place substitution ----
        $homoglyphs = taphish_homoglyph_map();
        $name_len = strlen($name);
        for ($i = 0; $i < $name_len; $i++) {
            $ch = $name[$i];
            if (!isset($homoglyphs[$ch])) continue;
            foreach ($homoglyphs[$ch] as $glyph) {
                $variant = substr($name, 0, $i) . $glyph . substr($name, $i + 1);
                // ASCII-only swap (digit zero for o, "1" for l, "rn" for m)
                // ranks slightly higher than unicode swaps because the
                // operator can register them without IDN paperwork.
                $score = (strlen($glyph) === 1 && ord($glyph) < 128) ? 88 : 90;
                $push($variant, $tld, 'homoglyph', $score);
            }
        }

        // ---- Character insertion (e.g. "taarget", "target-corp") ----
        $insert_suffixes = ['-corp', '-llc', '-secure', '-mail', '-login', '-account'];
        foreach ($insert_suffixes as $suf) {
            $push($name . $suf, $tld, 'insert', 65);
        }
        // Doubled-letter typos
        for ($i = 0; $i < $name_len; $i++) {
            $variant = substr($name, 0, $i + 1) . $name[$i] . substr($name, $i + 1);
            $push($variant, $tld, 'typo', 55);
        }
        // Single-letter drops
        for ($i = 0; $i < $name_len; $i++) {
            $variant = substr($name, 0, $i) . substr($name, $i + 1);
            if (strlen($variant) >= 3) {
                $push($variant, $tld, 'typo', 50);
            }
        }

        // ---- Qwerty-neighbour typos ----
        $qwerty = taphish_qwerty_neighbours();
        for ($i = 0; $i < $name_len; $i++) {
            $ch = $name[$i];
            if (!isset($qwerty[$ch])) continue;
            foreach ($qwerty[$ch] as $neighbour) {
                $variant = substr($name, 0, $i) . $neighbour . substr($name, $i + 1);
                $push($variant, $tld, 'typo', 60);
            }
        }

        // ---- TLD swap ----
        $tld_swaps = ['com', 'co', 'io', 'net', 'org', 'me', 'app', 'inc',
                      'com.co', 'org.uk', 'co.uk', 'ltd'];
        foreach ($tld_swaps as $alt) {
            if ($alt === $tld) continue;
            $push($name, $alt, 'tld', 40);
        }

        usort($out, function ($a, $b) {
            if ($a['score'] === $b['score']) {
                return strcmp($a['domain'], $b['domain']);
            }
            return $b['score'] <=> $a['score'];
        });
        if (count($out) > $limit) {
            $out = array_slice($out, 0, $limit);
        }
        return $out;
    }
}
