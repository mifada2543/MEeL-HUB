<?php
use PHPUnit\Framework\TestCase;

/**
 * Penjaga kontras WCAG AA untuk tombol SweetAlert2.
 *
 * TEMUAN (Lighthouse, 2026-10-14): SweetAlert2 memberi tombol konfirmasi
 * teks putih di atas warna latar yang dipilih lewat `confirmButtonColor`.
 * Semua warna terang Tailwind (shade 400/500) gagal jauh dari ambang AA
 * 4.5:1, dan hanya terlihat kalau dialog-nya kebetulan muncul saat
 * pengukuran. Sebagian besar halaman memakai Swal berada di balik login,
 * jadi audit anonim tidak akan pernah sampai ke sana.
 *
 * Semua warna yang dipakai sudah diperfixed ke shade lebih tua dari keluarga
 * Tailwind yang sama. Test ini menjaga agar tidak ada yang menambahkan
 * warna baru yang gagal.
 *
 * @coversNothing
 */
class SwalButtonContrastTest extends TestCase
{
    /**
     * @return array{0:int,1:int} [jumlah warn, jumlah gagal]
     */
    private static function relativeLuminance(string $hex): float|int
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            return -1;
        }

        $channels = [];
        foreach ([0, 2, 4] as $i) {
            $c = hexdec(substr($hex, $i, 2)) / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }
        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    private static function contrastWithWhite(string $hex): ?float
    {
        $lum = self::relativeLuminance($hex);
        if ($lum < 0) {
            return null;
        }
        return 1.05 / ($lum + 0.05);
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
     * Tombol yang TIDAK diberi `confirmButtonColor` memakai default library.
     *
     * Nilai default dibaca langsung dari berkas library
     * `assets/js/compatibilitas/sweetalert2.all.min.js` v11.26.25:
     *
     *   confirm  #7066e0 -> 4.5008:1
     *   deny     #dc3741 -> 4.5007:1
     *   cancel   #6e7881 -> 4.5001:1
     *
     * Ketiganya LULUS AA. Test membacanya dari berkas library, bukan dari angka
     * yang diketik tangan.
     */
    public function testLibraryDefaultButtonsMeetWcagAa(): void
    {
        $lib = MEEL_ROOT . '/assets/js/compatibilitas/sweetalert2.all.min.js';
        $this->assertFileExists($lib, 'sweetalert2.all.min.js tidak ditemukan');

        $src = (string) file_get_contents($lib);
        if (!preg_match('/version="([\d.]+)"/', $src, $v)) {
            $this->markTestSkipped('versi library tidak terbaca dari berkas');
        }

        $found = [];
        foreach (['confirm', 'deny', 'cancel'] as $button) {
            $needle = "--swal2-{$button}-button-background-color:";
            $this->assertStringContainsString(
                $needle,
                $src,
                "Nilai default tombol {$button} tidak ditemukan di library — pola berubah, "
                . 'angka di test ini harus ikut dibaca ulang dari berkas'
            );
            preg_match('/' . preg_quote($needle, '/') . '\s*(#[0-9a-fA-F]{3,6})/', $src, $m);
            $found[$button] = ['hex' => $m[1], 'ratio' => self::contrastWithWhite($m[1])];
        }

        $report = [];
        foreach ($found as $button => $d) {
            $report[] = sprintf('%s %s = %.4f:1', $button, $d['hex'], $d['ratio']);
            $this->assertGreaterThanOrEqual(
                4.5,
                $d['ratio'],
                sprintf(
                    'Tombol default %sSweetAlert2 (%s) hanya %.4f:1 — di bawah AA 4.5:1. '
                    . 'Semua dialog tanpa confirmButtonColor akan gagal kontras.',
                    $button,
                    $d['hex'],
                    $d['ratio']
                )
            );
        }

        fwrite(
            STDERR,
            sprintf(
                "[swal-contrast] v%s default: %s\n",
                $v[1],
                implode(' | ', $report)
            )
        );
    }
}

/* reference build: MEeL-C8H7NO2 [swal-button-contrast] */