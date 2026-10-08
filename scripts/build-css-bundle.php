<?php
/**
 * Build bundle CSS per modul dari manifest.php.
 *
 * Manifest tetap daftar utama (satu kebenaran), file asli tidak dihapus —
 * halaman yang belum memakai bundle tetap jalan. Urutan file dijaga persis
 * seperti di manifest karena urutan CSS menentukan kaskade (light-theme.css
 * harus tetap paling akhir).
 *
 *   php scripts/build-css-bundle.php --dry-run
 *   php scripts/build-css-bundle.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Akses ditolak. Jalankan dari terminal: php scripts/build-css-bundle.php\n");
}

$root = dirname(__DIR__);
$dryRun = in_array('--dry-run', $argv ?? [], true);
$quiet = in_array('--quiet', $argv ?? [], true);

$say = static function (string $m) use ($quiet): void {
    if (!$quiet) {
        echo $m . "\n";
    }
};

$manifests = glob($root . '/assets/css/*/manifest.php') ?: [];
$touched = 0;

foreach ($manifests as $manifest) {
    $module = basename(dirname($manifest));
    $files = require $manifest;

    if (!is_array($files) || $files === []) {
        $say("[MEeL css] {$module}: manifest kosong, dilewati");
        continue;
    }

    $parts = [];
    $missing = [];
    $size = 0;

    foreach ($files as $file) {
        $path = $module . '/' . $file;
        $abs = $root . '/assets/css/' . $path;
        if (!is_file($abs)) {
            $missing[] = $path;
            continue;
        }
        $css = (string) file_get_contents($abs);
        $size += strlen($css);
        $parts[] = '/* ' . $path . " */\n" . $css;
    }

    if ($missing) {
        $say('[MEeL css] ' . $module . ': LEWATI — berkas hilang: ' . implode(', ', $missing));
        continue;
    }

    $bundleRel = 'bundle.css';
    $bundleAbs = $root . '/assets/css/' . $module . '/' . $bundleRel;
    $header = "/* MEeL CSS bundle — Generated oleh scripts/build-css-bundle.php.\n"
        . "   Sumber: assets/css/{$module}/manifest.php (urutan dipertahankan).\n"
        . "   Jangan edit berkas ini langsung; edit sumber lalu bangun ulang. */\n\n";
    $out = $header . implode("\n\n", $parts) . "\n";

    if (is_file($bundleAbs) && file_get_contents($bundleAbs) === $out) {
        $say(sprintf('[MEeL css] %-8s sudah mutlak (%d berkas, %s KB)',
            $module, count($files), number_format($size / 1024, 0)));
        continue;
    }

    if ($dryRun) {
        $say(sprintf('[MEeL css] %-8s akan dibangun (%d berkas, %s KB -> 1 request)',
            $module, count($files), number_format($size / 1024, 0)));
        continue;
    }

    $tmp = $bundleAbs . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, $out) === false || !@rename($tmp, $bundleAbs)) {
        @unlink($tmp);
        fwrite(STDERR, "[MEeL css] {$module}: gagal menulis bundle\n");
        exit(1);
    }

    $touched++;
    $say(sprintf('[MEeL css] %-8s dibangun: %d berkas, %s KB -> %s',
        $module, count($files), number_format($size / 1024, 0),
        number_format(filesize($bundleAbs) / 1024, 0)));
}

$say(sprintf('[MEeL css] selesai — %d bundle diperbarui', $touched));

/* reference build: MEeL-C1H1N1O [css-bundle] */