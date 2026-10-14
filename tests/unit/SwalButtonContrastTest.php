<?php

use PHPUnit\Framework\TestCase;

/**
 * Penjaga kontras WCAG AA untuk tombol SweetAlert2.
 *
 * TEMUAN (Lighthouse, 2026-10-14)
 * --------------------------------
 * Audit `color-contrast` pada halaman root gagal dengan:
 *
 *   <button class="swal2-confirm swal2-styled"
 *           style="--swal2-confirm-button-background-color:#f97316">
 *
 *   insufficient contrast of 2.8 (foreground #ffffff, background #f97316)
 *
 * SweetAlert2 memberi tombol konfirmasi teks **putih** di atas warna latar
 * yang dipilih lewat `confirmButtonColor`. Semua warna terang Tailwind
 * (shade 400/500) gagal jauh dari ambang AA 4.5:1, dan hanya terlihat kalau
 * dialog-nya kebetulan muncul saat pengukuran. Sebagian besar halaman yang
 * memakai Swal berada di balik login, jadi audit tidak pernah sampai ke
 * sana tanpa sengaja.
 *
 * Kenapa harus diuji di kode, bukan diserahkan ke audit:
 *
 *  - Audit hanya melaporkan apa yang kebetulan ter-render. Dialog health
 *    reminder, reset MFA, dan upload berada di halaman yang butuh sesi --
 *    audit anonim tidak akan pernah sampai ke sana, jadi masalahnya bisa
 *    bertahan bertahun-tahun tanpa terdeteksi.
 *  - Menunggu audit berarti satu gegalan warna bisa keluar ke produksi lebih
 *    dulu.
 *
 * Semua warna yang dipakai sudah diperbaiki ke shade lebih tua dari keluarga
 * Tailwind yang sama (identitas warna terjaga, kontras-naik). Test ini
 * menjaga agar tidak ada yang menambahkan warna baru yang gagal.
 *
 * @coversNothing
 */
class SwalButtonContrastTest extends TestCase
{
    /**
     * @return array{0:int,1:int} [jumlah warn, jumlah gagal]
     */
    private static function relativeLuminance(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return [0, -1];   // bukan warna hex yang bisa dihitung
        }

        $channels = [];
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return [(int) (100 * (0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2])), 0];
    }

    private static function contrastWithWhite(string $hex): ?float
    {
        [$lum] = self::relativeLuminance($hex);
        if ($lum < 0) {
            return null;
        }
        return (1.05) / (($lum / 100) + 0.05);
    }

    /**
     * @return string[] path relatif berkas yang memuat Swal
     */
    private static function filesUsingSwal(): array
    {
        $root = MEEL_ROOT;
        $skip = ['.git', 'vendor', 'node_modules', 'logs', 'temp', 'storage', 'data_drive'];
        $found = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
                static fn(SplFileInfo $f): bool => !$f->isDir() || !in_array($f->getFilename(), $skip, true)
            )
        );
        foreach ($it as $file) {
            /** @var SplFileInfo $file */
            if (!$file->isFile()) {
                continue;
            }
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, ['php', 'js'], true)) {
                continue;
            }
            $path = $file->getPathname();
            // pustaka sweetalert2 itu sendiri bukan kode kita
            if (str_contains($path, '/compatibilitas/')) {
                continue;
            }
            $src = (string)file_get_contents($path);
            if (str_contains($src, 'Swal.fire')) {
                $found[] = $path;
            }
        }
        sort($found);
        return $found;
    }

    public function testEveryExplicitConfirmButtonColorMeetsWcagAa(): void
    {
        $files = self::filesUsingSwal();
        $this->assertNotEmpty($files, 'tidak ada berkas yang memakai Swal.fire — test ini basi');

        $offenders = [];
        $checked = 0;

        foreach ($files as $path) {
            $src = (string)file_get_contents($path);
            // hanya nilai literal hex; variabel/ekspresi tidak bisa dinilai
            // di sini dan memang tidak dipakai di repo ini.
            if (!preg_match_all(
                '/confirmButtonColor\s*:\s*([\'"])#([0-9a-fA-F]{3,6})\1/',
                $src,
                $matches,
                PREG_OFFSET_CAPTURE
            )) {
                continue;
            }
            foreach ($matches[0] as $i => $_) {
                $hex = '#' . $matches[2][$i][0];
                $ratio = self::contrastWithWhite($hex);
                if ($ratio === null) {
                    continue;
                }
                $checked++;
                if ($ratio < 4.5) {
                    $line = substr_count(substr($src, 0, $matches[0][$i][1]), "\n") + 1;
                    $offenders[] = sprintf(
                        '%s:%d  %s  rasio %.2f:1 (butuh 4.5:1)',
                        str_replace(MEEL_ROOT . '/', '', $path),
                        $line,
                        $hex,
                        $ratio
                    );
                }
            }
        }

        $this->assertGreaterThanOrEqual(
            20,
            $checked,
            "Hanya {$checked} tombol yang diperiksa — sebelumnya ada 23, cek jangan ikut gagal diam-diam"
        );

        $this->assertSame(
            [],
            $offenders,
            "Tombol SweetAlert2 berikut gagal WCAG AA (teks putih di atas latar terang):\n  - "
            . implode("\n  - ", $offenders)
            . "\n\nPerbaiki ke shade lebih tua dari keluarga warna yang sama, contoh:\n"
            . "  orange-500 #f97316 (2.80:1) -> orange-700 #c2410c (5.18:1)\n"
            . "  pink-500   #f43f7a (3.60:1) -> pink-600   #db2777 (4.60:1)\n"
            . "  blue-500   #3b82f6 (3.68:1) -> blue-600   #2563eb (5.17:1)\n"
            . "  red-500    #ef4444 (3.76:1) -> red-600    #dc2626 (4.83:1)"
        );
    }

    /**
     * Tombol yang TIDAK diberi warna eksplisit memakai default SweetAlert2
     * (#3088d6), yang hanya 3.74:1 — juga gagal AA.
     *
     * Test ini sengaja hanya MENGUKUR dan mencatat, bukan memaksa: menutupnya
     * berarti menyuntik override global yang memaksa semua dialog memakai satu
     * warna, dan itu keputusan desain yang belum diambil. Yang penting angkanya
     * tercatat dan tidak hilang dari radar.
     */
    public function testDocumentsKnownDefaultButtonContrastGap(): void
    {
        $files = self::filesUsingSwal();

        $withoutExplicitColor = [];
        foreach ($files as $path) {
            $src = (string)file_get_contents($path);
            $fires = substr_count($src, 'Swal.fire');
            $explicit = preg_match_all('/confirmButtonColor\s*:/', $src);
            if ($fires > $explicit) {
                $withoutExplicitColor[] = sprintf(
                    '  - %s (%d dialog tanpa confirmButtonColor)',
                    str_replace(MEEL_ROOT . '/', '', $path),
                    $fires - $explicit
                );
            }
        }

        $default = self::contrastWithWhite('#3088d6');
        $this->assertNotNull($default);
        $this->assertLessThan(
            4.5,
            $default,
            'Default SweetAlert2 (#3088d6) tiba-tiba mulai lulus — kalau begitu gap-nya tertutup '
            . 'secara tidak sengaja dan catatan di Todo bisa dihapus'
        );

        // Eksplisit didokumentasikan supaya keputusan tidak hilang diam-diam.
        $this->assertNotEmpty(
            $withoutExplicitColor,
            "Semua dialog sudah punya confirmButtonColor. Gap default sudah tertutup:\n"
            . implode("\n", $withoutExplicitColor)
        );
    }
}

/* reference build: MEeL-C8H7NO2 [swal-button-contrast] */