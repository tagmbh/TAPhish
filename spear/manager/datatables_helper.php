<?php
// Shared DataTables server-side response contract (built test-first).
// Fixes the "Next disabled" bug: recordsFiltered MUST be a real filtered COUNT,
// never sizeof() of the already-LIMIT-sliced rows.

if (!function_exists('taphish_dt_envelope')) {
    /**
     * The response contract. `recordsFiltered` gates the Next button, so it must
     * be the total number of rows matching the search (a real COUNT) — NOT the
     * size of the current page ($rows), which was already LIMIT-sliced.
     */
    function taphish_dt_envelope(int $draw, int $recordsTotal, int $recordsFiltered, array $rows): array
    {
        return [
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $rows,
        ];
    }
}

if (!function_exists('taphish_dt_search_clause')) {
    /**
     * Build a parameterised `(col LIKE ? OR col LIKE ?)` fragment + binds for a
     * global search term. Empty term or no columns → empty (no WHERE addition).
     * Column names are caller-supplied identifiers (trusted); values are bound.
     *
     * @return array{sql:string, binds:array<int,string>}
     */
    function taphish_dt_search_clause(array $cols, string $term): array
    {
        $term = trim($term);
        if ($term === '' || $cols === []) {
            return ['sql' => '', 'binds' => []];
        }
        $parts = $binds = [];
        foreach ($cols as $c) {
            $parts[] = $c . ' LIKE ?';
            $binds[] = '%' . $term . '%';
        }
        return ['sql' => '(' . implode(' OR ', $parts) . ')', 'binds' => $binds];
    }
}

if (!function_exists('taphish_dt_order_clause')) {
    /**
     * Whitelisted ORDER BY. The column MUST be in $allowed (exact match) or the
     * function returns '' — this is the only injection guard on the sort column.
     */
    function taphish_dt_order_clause(array $allowed, string $col, string $dir): string
    {
        if (!in_array($col, $allowed, true)) {
            return '';
        }
        $dir = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        return 'ORDER BY ' . $col . ' ' . $dir;
    }
}

if (!function_exists('taphish_dt_limit')) {
    /**
     * Clamp DataTables start/length. length === -1 means "All" and passes
     * through so the caller can omit the LIMIT; start is clamped to >= 0.
     *
     * @return array{0:int,1:int} [start, length]
     */
    function taphish_dt_limit($start, $length): array
    {
        $start  = max(0, (int) $start);
        $length = (int) $length;
        return [$start, $length];
    }
}
