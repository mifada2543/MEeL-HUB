<?php
/**
 * Periksa dan perbaiki thumbnail dengan ekstensi WebP yang keliru.
 * Tanpa --apply, tidak ada file atau direktori yang ditulis.
 * PEMAKAIAN
 *   php scripts/repair-mislabeled-thumbnails.php
 *   php scripts/repair-mislabeled-thumbnails.php --apply
 *   php scripts/repair-mislabeled-thumbnails.php --restore
 *   Tambahkan --dir=<path> (berulang) dan --backup-dir=<path> bila diperlukan.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('Akses ditolak. Jalankan dari terminal.');
}
$dirs = [];
$backup = '';
$apply = false;
$restore = false;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--restore') {
        $restore = true;
    } elseif (str_starts_with($arg, '--dir=')) {
        $dirs[] = substr($arg, 6);
    } elseif (str_starts_with($arg, '--backup-dir=')) {
        $backup = substr($arg, 13);
    } else {
        fwrite(STDERR, "Opsi tidak dikenal: {$arg}\n");
        exit(2);
    }
}
if ($apply && $restore) {
    fwrite(STDERR, "Pilih --apply atau --restore, bukan keduanya.\n");
    exit(2);
}
// Direktori eksplisit tidak memerlukan konfigurasi produksi atau koneksi DB.
if (!$dirs && is_file(__DIR__ . '/../auth/settings.php')) {
    require_once __DIR__ . '/../auth/settings.php';
}
require_once __DIR__ . '/../modules/core/helpers/storage_paths.php';
require_once __DIR__ . '/../modules/autoload.php';
try {
    $base = $backup !== '' ? $backup : ((meel_storage_base_path() ?: sys_get_temp_dir())
        . (meel_storage_base_path() !== '' ? '/.thumbnail-repair-backup' : '/meel-thumbnail-repair-backup'));
    if ($restore) {
        $runs = glob($base . '/run-*', GLOB_ONLYDIR) ?: [];
        rsort($runs, SORT_STRING);
        $run = '';
        foreach (array_merge([$base], $runs) as $candidate) {
            $manifestPath = $candidate . '/manifest.json';
            if (!is_file($manifestPath)) {
                continue;
            }
            $manifest = json_decode(file_get_contents($manifestPath), true);
            if (!is_array($manifest) || !$manifest) {
                continue;
            }
            $valid = true;
            foreach ($manifest as $original => $entry) {
                if (!is_string($original) || !is_array($entry)
                    || !isset($entry['backup'], $entry['original_hash'], $entry['repaired_hash'])
                    || !is_string($entry['backup']) || !is_string($entry['original_hash']) || !is_string($entry['repaired_hash'])) {
                    $valid = false;
                    break;
                }
            }
            if ($valid) {
                $run = $candidate;
                break;
            }
        }
        if ($run === '') {
            throw new RuntimeException('Tidak ada cadangan untuk dipulihkan.');
        }
        $results = (new ThumbnailRepair($run))->restore($run);
    } else {
        $dirs = $dirs ?: array_map(static fn($module) => meel_media_base_path($module) . '/thumbnail/', ['video', 'music', 'books']);
        $run = $apply ? $base . '/run-' . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) : $base;
        $tool = new ThumbnailRepair($run);
        $entries = $tool->inventory($dirs);
        printf("Dipindai: %d; salah label: %d\n", $tool->scanned, count($entries));
        $results = [];
        foreach ($entries as $entry) {
            printf("%s (%s)\n", $entry['path'], $entry['mime']);
            if ($apply) {
                $results[] = $tool->repair($entry['path']);
            }
        }
        echo $apply ? "Cadangan: {$run}\n" : "Mode laporan saja. Tidak ada penulisan.\n";
    }
    $failed = 0;
    foreach ($results as $result) {
        $labels = ['repaired' => 'diperbaiki', 'restored' => 'dipulihkan', 'skipped' => 'dilewati', 'failed' => 'gagal'];
        printf("%s: %s %s\n", $labels[$result['status']], $result['path'], $result['error']);
        $failed += $result['status'] === 'failed' ? 1 : 0;
    }
    printf("Diperbaiki: %d; dipulihkan: %d; gagal: %d\n", count(array_filter($results, static fn($r) => $r['status'] === 'repaired')),
        count(array_filter($results, static fn($r) => $r['status'] === 'restored')), $failed);
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Gagal: ' . $e->getMessage() . "\n");
    exit(1);
}
/* reference build: MEeL-C10H16N [83bf14d9] */
