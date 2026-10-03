<?php
if (!function_exists('meel_media_table_whitelist')) {
function meel_media_table_whitelist(): array
{
    return ['video', 'music', 'books'];
}
}

if (!function_exists('meel_media_cache_dir')) {
function meel_media_cache_dir(): string
{
    $dir = defined('MEEL_MEDIA_CACHE_DIR')
        ? MEEL_MEDIA_CACHE_DIR
        : (getenv('MEEL_MEDIA_CACHE_DIR') ?: dirname(__DIR__, 3) . '/temp/cache');

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    return rtrim($dir, '/');
}
}

if (!function_exists('meel_media_stats_all')) {

function meel_media_stats_all(mysqli $conn, int $ttl = 30): array
{
    $tables = meel_media_table_whitelist();
    $dir = meel_media_cache_dir();

    $out = [];
    $stale = [];
    $now = time();

    foreach ($tables as $table) {
        $file = $dir . '/media_stats_' . $table . '.json';
        $cached = null;
        if ($ttl > 0 && is_readable($file)) {
            $raw = @file_get_contents($file);
            if ($raw !== false && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)
                    && isset($decoded['total'], $decoded['min_id'], $decoded['max_id'], $decoded['ts'])
                    && ($now - (int)$decoded['ts']) < $ttl
                ) {
                    $cached = [
                        'total'  => (int)$decoded['total'],
                        'min_id' => (int)$decoded['min_id'],
                        'max_id' => (int)$decoded['max_id'],
                    ];
                }
            }
        }
        if ($cached !== null) {
            $out[$table] = $cached;
        } else {
            $stale[] = $table;
        }
    }

    if ($stale !== []) {
        $empty = ['total' => 0, 'min_id' => 0, 'max_id' => 0];
        $fresh = array_fill_keys($stale, $empty);

        $parts = [];
        foreach ($stale as $table) {
            $parts[] = "SELECT '{$table}' AS t, COUNT(*) AS c,"
                . " COALESCE(MIN(id), 0) AS mn, COALESCE(MAX(id), 0) AS mx FROM `{$table}`";
        }

        $res = $conn->query(implode(' UNION ALL ', $parts));
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $t = (string)($row['t'] ?? '');
                if (!in_array($t, $stale, true)) {
                    continue;
                }
                $fresh[$t] = [
                    'total'  => (int)($row['c'] ?? 0),
                    'min_id' => (int)($row['mn'] ?? 0),
                    'max_id' => (int)($row['mx'] ?? 0),
                ];
            }
            $res->free();
        }

        foreach ($fresh as $table => $stats) {
            if ($ttl > 0 && function_exists('meel_write_cache_file')) {
                meel_write_cache_file(
                    $dir . '/media_stats_' . $table . '.json',
                    json_encode($stats + ['ts' => $now])
                );
            }
        }

        $out += $fresh;
    }

    return $out;
}
}

if (!function_exists('meel_media_stats')) {

function meel_media_stats(mysqli $conn, string $table, int $ttl = 30): array
{
    if (!in_array($table, meel_media_table_whitelist(), true)) {
        return ['total' => 0, 'min_id' => 0, 'max_id' => 0];
    }

    $all = meel_media_stats_all($conn, $ttl);
    return $all[$table] ?? ['total' => 0, 'min_id' => 0, 'max_id' => 0];
}
}

if (!function_exists('meel_invalidate_media_stats_cache')) {

function meel_invalidate_media_stats_cache(?string $table = null): void
{
    $dir = meel_media_cache_dir();
    $pattern = $table !== null && in_array($table, meel_media_table_whitelist(), true)
        ? $dir . '/media_stats_' . $table . '.json'
        : $dir . '/media_stats_*.json';

    foreach (glob($pattern) ?: [] as $file) {
        @unlink($file);
    }
}
}

if (!function_exists('meel_pick_random_ids')) {

function meel_pick_random_ids(
    mysqli $conn,
    string $table,
    int $limit,
    int $primaryExclude = 0,
    array $seenIds = [],
    ?string $seenSessionKey = null,
    int $windowSize = 25,
    int $maxWindows = 6
): array {
    $limit = max(0, $limit);
    if ($limit === 0 || !in_array($table, meel_media_table_whitelist(), true)) {
        return [];
    }

    $stats = meel_media_stats($conn, $table);
    if ($stats['total'] <= 1) {
        return [];
    }

    $want = $limit * 2;
    $span = $stats['max_id'] - $stats['min_id'] + 1;

    $pool = [];
    if ($span <= 0) {
        $pool = [];
    } else {
        $attempts = max(1, min($maxWindows, (int)ceil($want / max(1, $windowSize))));
        for ($i = 0; $i < $attempts && count($pool) < $want; $i++) {
            $start = $stats['min_id'] + random_int(0, max(0, $span - 1));

            $stmt = $conn->prepare(
                "SELECT id FROM `{$table}` WHERE id >= ? ORDER BY id LIMIT ?"
            );
            if (!$stmt) {
                break;
            }
            $stmt->bind_param("ii", $start, $windowSize);
            if (!$stmt->execute()) {
                $stmt->close();
                break;
            }
            $res = $stmt->get_result();
            while ($res && ($row = $res->fetch_assoc())) {
                $pool[] = (int)$row['id'];
            }
            $stmt->close();

            if (count($pool) < $want && $i + 1 === $attempts && $start > $stats['min_id']) {
                $from = $stats['min_id'] + random_int(0, max(0, $start - $stats['min_id'] - 1));
                $stmt2 = $conn->prepare(
                    "SELECT id FROM `{$table}` WHERE id >= ? ORDER BY id LIMIT ?"
                );
                if ($stmt2) {
                    $stmt2->bind_param("ii", $from, $windowSize);
                    if ($stmt2->execute()) {
                        $res2 = $stmt2->get_result();
                        while ($res2 && ($row = $res2->fetch_assoc())) {
                            $pool[] = (int)$row['id'];
                        }
                    }
                    $stmt2->close();
                }
            }
        }

        $pool = array_values(array_unique($pool));
    }

    if (count($pool) < $limit) {
        $extra = [];
        $res = $conn->query("SELECT id FROM `{$table}` ORDER BY id LIMIT " . max($want, 50));
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $extra[] = (int)$row['id'];
            }
            $res->free();
        }
        if ($extra !== []) {
            $merged = [];
            foreach (array_merge($pool, $extra) as $id) {
                $merged[(int)$id] = true;
            }
            $pool = array_map('intval', array_keys($merged));
        }
    }

    $available = array_values(array_diff($pool, array_merge([$primaryExclude], $seenIds)));

    if (empty($available) && $seenSessionKey !== null) {
        $_SESSION[$seenSessionKey] = [];
        $available = array_values(array_diff($pool, [$primaryExclude]));
    }

    if (empty($available)) {
        return [];
    }

    shuffle($available);

    return array_slice($available, 0, $limit);
}
}

/* reference build: MEeL-C1M1D1A1 [7c02e5b41d9af836] */
