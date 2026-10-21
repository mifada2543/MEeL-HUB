<?php
/**
 * Generator manifest.json (Web App Manifest).
 *
 * Kenapa bukan file JSON statis anymore?
 * --------------------------------------
 * Sebelumnya `assets/manifest.json` adalah file statis dengan
 * `"scope": "/MEeL/"` dan `"id": "/MEeL/"` HARDCODE. Dua masalah:
 *
 *  1. **Pindah folder = PWA rusak.** Repo ini di-clone ke folder bernama
 *     apa pun (README cuma bilang `git clone ... MEeL`, tapi user bebas
 *     menamai ulang). Kalau foldernya `media-hub`, `scope` tetap
 *     `/MEeL/` → service worker tidak pernah controlling halaman, dan
 *     shortcut ikut mati.
 *  2. **`start_url`/shortcut berisi `.php`.** URL yang di-cacheSW jadi
 *     terikat pada nama file; mengubah nama file langsung merusak cache.
 *
 * Pendekatan ini mengikuti pola yang SUDAH dipakai repo untuk
 * `/sw.js` → `sw.js.php` (lihat .htaccess: `RewriteRule ^sw\.js$`):
 * file `.json` statis tetap ada sebagai fallback (clone tanpa
 * mod_rewrite, atau Dokumentasi yang dibaca langsung), tapi yang
 * dilayani browser adalah hasil generator di sini.
 *
 * Base path diambil dari meel_base_url_path() — sama dengan yang dipakai
 * sitemap.php, auth/config.php, dan seluruh halaman lain. Jadi cukup
 * dipindah sekali, semua ikut benar.
 */

if (PHP_SAPI !== 'cli' && !isset($_SERVER['REQUEST_METHOD'])) {
    // Di CLI pun (mis. dipanggil dari generator lain) tetap boleh.
}

// base_url.php dimuat oleh bootstrap.php, BUKAN helpers.php. Generator ini
// tidak mau menarik seluruh bootstrap (auth, session, DB) hanya untuk satu
// fungsi path — jadi memuat file yang tepat secara langsung. Kalau file
// hilang, jatuhkan ke '/' daripada 500 (PWA yang rusak scope lebih baik
// daripada halaman manifest yang error).
if (!function_exists('meel_base_url_path')) {
    require_once __DIR__ . '/modules/core/base_url.php';
}

// Hook test: DOCUMENT_ROOT tidak diwariskan ke subprocess PHP CLI, jadi
// test perlu menyuntikkannya lewat env var. Production tidak pernah
// menyetel env ini — nilainya selalu kosong saat web server memanggil.
$docRootOverride = getenv('MEEL_MANIFEST_DOCROOT');
if (is_string($docRootOverride) && $docRootOverride !== '') {
    $_SERVER['DOCUMENT_ROOT'] = $docRootOverride;
}

$base = function_exists('meel_base_url_path') ? meel_base_url_path() : '';

// meel_base_url_path() menghitung selisih antara path proyek dan
// DOCUMENT_ROOT. Kalau DOCUMENT_ROOT kosong atau bukan prefix dari path
// proyek (CLI, cron, symlink), hasilnya bisa berupa PATH ABSOLUT
// filesystem seperti "/opt/lampp/htdocs/MEeL" — yang sama sekali bukan
// URL.
// Guard: hasil WAJIB berupa path relatif yang diawali "/", atau kosong.
// Anything else dibuang dan jatuh ke "/" — lebih baik PWA ter-scope di
// root daripada URL yang berisi path filesystem.
$base = trim((string)$base);

// meel_base_url_path() = substr(project_root, strlen(doc_root)). Kalau
// DOCUMENT_ROOT kosong, strlen("") = 0, jadi hasilnya PATH PROYEK PENUH
// seperti "/opt/lampp/htdocs/MEeL" — sama sekali bukan URL.
// Deteksi yang andal: kalau hasil base == path proyek, berarti
// DOCUMENT_ROOT memang tidak menyumbang apa pun. Bandingkan langsung,
// jangan lewat heuristik.
// CATATAN: path proyek harus dihitung dari ISI FILE INI (manifest.php ada
// di root repo), bukan dirname(__DIR__) dari base_url.php — base_url.php
// tinggal di modules/core/, jadi __DIR__ di sana satu level lebih dalam.
$projRoot = rtrim(str_replace('\\', '/', __DIR__), '/');

// Guard berlaku untuk hasil yang SAMA PERSIS dengan path proyek, maupun
// hasil yang memuatnya sebagai prefix. DOCUMENT_ROOT bisa sedikit
// berbeda dari path proyek (symlink, trailing slash), jadi perbandingan
// string eksak saja tidak selalu kena.
if ($base !== '' && (
    $base === $projRoot
    || str_starts_with($projRoot, $base . '/')
    || str_starts_with($base, $projRoot . '/')
)) {
    error_log(
        '[manifest.php] DOCUMENT_ROOT kosong/tidak cocok — meel_base_url_path() mengembalikan '
        . 'path filesystem (' . $base . '), scope dipaksa ke "/"'
    );
    $base = '';
}

$base = '/' . trim($base, '/');
if ($base === '/') {
    $base = '';
}
// Scope SELALU diakhiri "/" supaya cocok dengan `scope` bawaan browser
// (yang selalu berekspresi), dan agar URL di bawahnya tidak pernah
// jadi "/MEeLvideo/..." (slash hilang).
$scope = $base === '' ? '/' : $base . '/';

$jsonPath = __DIR__ . '/assets/manifest.json';
$raw = is_file($jsonPath) ? (string)file_get_contents($jsonPath) : '{}';
$manifest = json_decode($raw, true);

if (!is_array($manifest)) {
    $manifest = [
        'short_name' => 'MEeL',
        'name'        => 'MEeL — Media Hub Platform',
        'description' => 'Platform Media Hub Pribadi untuk Streaming Video, Musik, Buku Digital, dan Cloud Storage.',
        'icons'       => [],
    ];
}

/**
 * Ubah URL yang tertulis di assets/manifest.json (yang relatif terhadap
 * folder `assets/`) menjadi URL absolut terhadap root aplikasi.
 *
 * Manifest dilayani dari `assets/manifest.json`, jadi `MEeL-192.png`
 * berarti `<base>/assets/MEeL-192.png`, dan `../video/index.php`
 * berarti `<base>/video/index.php`.
 *
 * Hasil SELALU di-root (dimulai dengan "/") supaya tidak bergantung
 * folder tempat manifest diletakkan.
 */
$absolutize = static function (string $url) use ($base): string {
    if ($url === '') {
        return $base === '' ? '/' : $base;
    }
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url)) {
        return $url; // URL eksternal (http:, data:, blob:) — jangan diubah.
    }
    if (str_starts_with($url, '/')) {
        // Sudah absolut dari root SERVER: prefix dengan base path aplikasi.
        return $base . ltrim($url, '/');
    }

    // Relatif terhadap assets/: "../x" keluar satu level, "./x" & "x" tetap.
    $segments = [];
    foreach (explode('/', $url) as $seg) {
        if ($seg === '.' || $seg === '') {
            continue;
        }
        if ($seg === '..') {
            array_pop($segments);
            continue;
        }
        $segments[] = $seg;
    }

    return ($base === '' ? '' : $base) . '/' . implode('/', $segments);
};

/* ── scope & id dinamis ───────────────────────────────────────────
 * `scope` menentukan halaman mana yang dikontrol SW.
 * `id` harus STABLE (tidak boleh berubah tiap deploy) — Chrome
 * memperlakukannya sebagai identitas aplikasi. Pakai base path,
 * bukan hash konten.
 */
$manifest['scope'] = $scope;
$manifest['id']    = $scope;

if (isset($manifest['start_url']) && is_string($manifest['start_url'])) {
    $manifest['start_url'] = $absolutize($manifest['start_url']);
}

if (isset($manifest['shortcuts']) && is_array($manifest['shortcuts'])) {
    foreach ($manifest['shortcuts'] as &$sc) {
        if (isset($sc['url']) && is_string($sc['url'])) {
            $sc['url'] = $absolutize($sc['url']);
        }
    }
    unset($sc);
}

/* ── icon absolutkan juga ───────────────────────────────────────────
 * PENTING: `src` di manifest relatif terhadap DOKUMEN yang memakainya
 * (manifest.json berada di assets/). Jadi "MEeL-192.png" berarti
 * `<base>/assets/MEeL-192.png`. Fungsi di atas mengasumsikan relatif
 * terhadap root, jadi ikon perlu satu level base eksplisit.
 */
$absolutizeAsset = static function (string $src) use ($absolutize, $base): string {
    // Kalau sudah berupa URL absolut / eksternal, biarkan.
    if (str_starts_with($src, '/') || preg_match('#^[a-z][a-z0-9+.-]*:#i', $src)) {
        return $absolutize($src);
    }
    // Relative ke assets/: naik ke base aplikasi dulu, baru ke asset.
    return $absolutize('assets/' . ltrim($src, './'));
};

if (isset($manifest['icons']) && is_array($manifest['icons'])) {
    foreach ($manifest['icons'] as &$icon) {
        if (isset($icon['src']) && is_string($icon['src'])) {
            $icon['src'] = $absolutizeAsset($icon['src']);
        }
    }
    unset($icon);
}

if (!headers_sent()) {
    header('Content-Type: application/manifest+json; charset=utf-8');
    // Manifest berubah hanya kalau base path berubah, tapi browser
    //キャッシュ agresif soal PWA — pakai revalidate, bukan immutable.
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
}

echo json_encode(
    $manifest,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
), "\n";