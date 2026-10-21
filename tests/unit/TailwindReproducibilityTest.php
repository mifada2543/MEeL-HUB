<?php
use PHPUnit\Framework\TestCase;

/**
 * Penjaga reproducibility `assets/css/tailwind.min.css`.
 *
 * Artefak hasil build yang di-commit harus bisa direproduksi. Yang lebih
 * halus dan lebih berbahaya: config bisa saja salah ketik dan build tetap
 * "sukses" dengan CSS setengah kosong — Tailwind tidak pernah gagal pada
 * glob yang salah, ia hanya diam-diam menghasilkan CSS yang lebih kecil.
 *
 * Test ini mengunci tiga hal, semuanya analisis statis supaya jalan di
 * suite unit biasa tanpa Node:
 *
 *   1. Entry build-nya ada dan saling konsisten.
 *   2. Glob `content` benar-benar memindai repo, dan tidak memindai folder
 *      yang harus dikecualikan (test, vendor, cache).
 *   3. Tema kustom yang dibutuhkan halaman tetap ada di config.
 *
 * Perbandingan nilai CSS ada di `scripts/ci/tailwind-coverage-check.php` —
 * butuh Tailwind CLI, jadi tidak dibuat jadi test unit.
 *
 * @coversNothing
 */
class TailwindReproducibilityTest extends TestCase
{
    private string $config;
    private string $committed;

    /** Folder yang TIDAK BOLEH masuk content glob, apa pun isi config. */
    private const MUST_EXCLUDE = [
        '.git',
        'vendor',
        'node_modules',
        'logs',
        'temp',
        'storage',
        'data_drive',
        'tests',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $configPath = MEEL_ROOT . '/tailwind.config.js';
        $this->assertFileExists($configPath, 'tailwind.config.js harus ada di repo');
        $this->config = (string)file_get_contents($configPath);

        $cssPath = MEEL_ROOT . '/assets/css/tailwind.min.css';
        $this->assertFileExists($cssPath, 'assets/css/tailwind.min.css harus ada');
        $this->committed = (string)file_get_contents($cssPath);
    }

    /**
     * @return array{0: string[], 1: string[]} [glob positif, glob negatif]
     */
    private function contentGlobs(): array
    {
        $this->assertMatchesRegularExpression(
            '/content\s*:\s*\[(.*?)\]/s',
            $this->config,
            'tailwind.config.js harus punya blok `content: [...]`'
        );
        preg_match('/content\s*:\s*\[(.*?)\]/s', $this->config, $m);
        preg_match_all('/\'([^\']+)\'|"([^"]+)"/', $m[1], $q);

        $positive = [];
        $negative = [];
        foreach ($q[1] as $i => $glob) {
            $glob = $glob !== '' ? $glob : ($q[2][$i] ?? '');
            if ($glob === '') {
                continue;
            }
            if (str_starts_with($glob, '!')) {
                $negative[] = substr($glob, 1);
            } else {
                $positive[] = $glob;
            }
        }
        return [$positive, $negative];
    }

    public function testBuildEntryPointsExist(): void
    {
        $this->assertFileExists(
            MEEL_ROOT . '/package.json',
            'package.json harus ada — tanpa itu `npm run css:build` tidak bisa dijalankan'
        );

        $pkg = json_decode((string)file_get_contents(MEEL_ROOT . '/package.json'), true);
        $this->assertIsArray($pkg, 'package.json harus JSON valid');

        $this->assertArrayHasKey(
            'css:build',
            $pkg['scripts'] ?? [],
            'package.json harus punya script `css:build`'
        );
        $this->assertStringContainsString(
            'tailwindcss',
            $pkg['scripts']['css:build'],
            'script css:build harus memanggil Tailwind CLI'
        );
        $this->assertStringContainsString(
            'tailwind.config.js',
            $pkg['scripts']['css:build'],
            'script css:build harus menunjuk config eksplisit — kalau tidak, Tailwind '
            . 'mencari sendiri dan bisa diam-diam memakai config lain'
        );

        $this->assertArrayHasKey(
            'tailwindcss',
            $pkg['devDependencies'] ?? [],
            'tailwindcss harus ada di devDependencies'
        );
        $this->assertMatchesRegularExpression(
            '/^\d+\.\d+\.\d+$/',
            (string) $pkg['devDependencies']['tailwindcss'],
            'Versi tailwindcss harus dipin ke tiga angka (mis. 3.4.19), bukan latest/range — '
            . 'versi mengarang hasil CSS berbeda dan build tetap terlihat sukses'
        );

        $this->assertFileExists(
            MEEL_ROOT . '/tools/tailwind/input.css',
            'Entry CSS Tailwind harus ada'
        );
        $input = (string)file_get_contents(MEEL_ROOT . '/tools/tailwind/input.css');
        foreach (['@tailwind base', '@tailwind components', '@tailwind utilities'] as $directive) {
            $this->assertStringContainsString(
                $directive,
                $input,
                "input.css harus memuat `{$directive}` — tanpa salah satu, lapisan itu hilang dari CSS"
            );
        }
    }

    /**
     * Glob yang salah ketik TIDAK membuat build gagal — Tailwind hanya
     * menghasilkan CSS lebih kecil. Test ini memastikan daftar file yang
     * terpindai tidak nol dan folder terlarang tidak ikut masuk.
     */
    public function testContentGlobScansRepoAndRespectsExclusions(): void
    {
        [$positive, $negative] = $this->contentGlobs();

        $this->assertNotEmpty($positive, 'Content glob positif tak boleh kosong');
        $this->assertStringContainsString(
            'php',
            implode(' ', $positive),
            'Content glob harus memindai .php — kalau tidak, semua utility yang dipakai '
            . 'halaman PHP hilang dari CSS'
        );

        $extensions = [];
        foreach ($positive as $glob) {
            if (preg_match('/\{([a-z0-9,]+)\}$/i', $glob, $m)) {
                foreach (explode(',', $m[1]) as $ext) {
                    $extensions[strtolower($ext)] = true;
                }
            }
        }
        $this->assertNotEmpty(
            $extensions,
            'Content glob harus punya ekstensi brace (mis. {php,js,html}) agar bisa dipindai'
        );

        $scanned = [];
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(MEEL_ROOT, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $entry) {
            /** @var SplFileInfo $entry */
            if (!$entry->isFile()) {
                continue;
            }
            $path = $entry->getPathname();
            $rel = './' . ltrim(substr($path, strlen(MEEL_ROOT)), '/');

            $excluded = false;
            foreach (array_merge(self::MUST_EXCLUDE, self::excludedDirs($negative)) as $dir) {
                if (str_starts_with($rel, './' . $dir . '/')) {
                    $excluded = true;
                    break;
                }
            }
            if ($excluded) {
                continue;
            }
            if (isset($extensions[strtolower($entry->getExtension())])) {
                $scanned[] = $rel;
            }
        }

        $this->assertGreaterThan(
            100,
            count($scanned),
            'Content glob hanya memindai ' . count($scanned) . ' file — hampir pasti salah ketik'
        );

        foreach (self::MUST_EXCLUDE as $dir) {
            $leaked = array_values(array_filter(
                $scanned,
                static fn(string $f): bool => str_starts_with($f, './' . $dir . '/')
            ));
            $this->assertSame(
                [],
                $leaked,
                sprintf(
                    'Folder %s/ ikut terpindai (%d file, mis. %s) — class milik test/fixture '
                    . 'masuk ke CSS produksi',
                    $dir,
                    count($leaked),
                    $leaked[0] ?? '-'
                )
            );
        }
    }

    /**
     * @param string[] $negative
     * @return string[]
     */
    private static function excludedDirs(array $negative): array
    {
        $dirs = [];
        foreach ($negative as $glob) {
            if (preg_match('#^\./([^/]+)/\*\*$#', $glob, $m)) {
                $dirs[] = $m[1];
            }
        }
        return $dirs;
    }

    /**
     * Tema kustom yang dipakai halaman sebagai class utility. Semuanya
     * berdasar class yang benar-benar muncul di markup —nilainya diekstrak
     * dari build yang menghasilkan artefak ter-commit, bukan ditebak.
     */
    public function testCustomThemeValuesUsedByMarkupAreStillConfigured(): void
    {
        $required = [
            'bg: \'#080b11\''      => 'class bg-bg',
            'muted: \'#455060\''   => 'class text-muted',
            'card: \'#0e1118\''    => 'class bg-card',
            'ink: {'              => 'class bg-ink-2 / bg-ink-3',
            "'DM Sans'"            => 'font default halaman (preflight ikut berubah)',
            "'IBM Plex Mono'"      => 'font kode/kbd',
            "'Fraunces'"           => 'class font-display',
            "'Syne'"               => 'class font-syne',
            'cardIn'               => 'animate-card-in',
            'iconPop'              => 'animate-icon-pop',
            'indeterminate'        => 'animate-indeterminate',
        ];

        foreach ($required as $needle => $usage) {
            $this->assertStringContainsString(
                $needle,
                $this->config,
                "tailwind.config.js kehilangan \"{$needle}\" — dipakai untuk {$usage}"
            );
        }

        $this->assertDoesNotMatchRegularExpression(
            '/cubic-bezier\(\s*[-\d.]+\s*,\s*[-\d.]+\s*,\s*[-\d.]+\s*\)/',
            $this->config,
            'cubic-bezier() wajib empat angka — tiga angka tidak valid dan membuat '
            . 'seluruh deklarasi animation dibuang browser'
        );
    }

    /**
     * Artefak ter-commit harus benar-benar memuat hasil build, bukan
     * sekadar file yang punya nama benar. Kalau build tidak pernah
     * dijalankan, file ini bisa tetap berisi CSS lama selamanya tanpa
     * ada yang protes.
     */
    public function testCommittedArtifactContainsWhatConfigProduces(): void
    {
        foreach ([
            '*,:after,:before'   => 'preflight Tailwind',
            '--tw-shadow'        => 'utility shadow',
            'border-width:0'     => 'reset border bawaan preflight',
            '@keyframes cardIn'  => 'animate-card-in',
            '@keyframes iconPop' => 'animate-icon-pop',
            '@keyframes indeterminate' => 'animate-indeterminate',
        ] as $needle => $layer) {
            $this->assertTrue(
                str_contains($this->committed, $needle),
                "assets/css/tailwind.min.css tidak memuat \"{$needle}\" ({$layer}) — artefak "
                . 'ter-commit tidak sinkron dengan config, atau build tidak pernah dijalankan'
            );
        }

        $this->assertLessThan(
            200_000,
            strlen($this->committed),
            'CSS ter-commit tumbuh tak wajar (>200 KB) — bukan hasil Tailwind CLI'
        );
    }
}

/* reference build: MEeL-C9H7NO3 [tailwind-reproducibility] */