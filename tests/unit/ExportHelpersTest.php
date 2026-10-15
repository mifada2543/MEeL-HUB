<?php

use PHPUnit\Framework\TestCase;

/**
 * Penjaga helper ekspor danqed rsp yang menggantikannya di
 * `admin/activity_log.php` (T19).
 *
 * APA YANG SEBENARNYA DUPLIKAT
 * ---------------------------
 * Todo menyebut "`Exporter::csv|json|xls()`" dengan rujukan baris
 * `:70-160` ≡ `:292-397`. Kelas `Exporter` **tidak pernah ada** dalam
 * seluruh riwayat git repo ini -- jadi nama dan bentuk yang disebut Todo
 * keliru. Yang benar ada, dan lebih buruk dari yang tertulis: ada DUA blok
 * ekspor di `admin/activity_log.php`, masing-masing dengan mode unduh dan
 * pratinjau, masing-masing dengan tiga format.
 *
 * ArtinyaSpecification kolom yang sama ditulis ulang **dua belas kali**:
 *
 *   antrean unggah: csv/json/xls unduh + csv/json/xls pratinjau  = 6
 *   log aktivitas:  csv/json/xls unduh + csv/json/xls pratinjau  = 6
 *
 * Plus premable XML SpreadsheetML (9 baris) dan closure $xls_cell, keduanya
 * duplikat byte-identik di dua blok.
 *
 * Bahaya yang sesungguhnya bukan panjang berkas. Kalau satu kolom ditambah
 * atau diurutkan ulang, ada sebelas tempat lain yang harus ikut berubah dan
 * tidak ada yang memberi tahu kalau lupa -- ekspor CSV bisa diam-diam punya
 * kolom berbeda dari ekspor JSON.
 *
 * APA YANG DIJAGA TEST INI
 * -----------------------
 * Test membandingkan helper baru dengan SEMANTIKA KODE LAMA yang ditulis
 * ulang di sini, bukan dengan output lama. Kalau kode lamanya
 * sendiri yang keliru, test ini tidak akan menangkapnya -- dan itu memang
 * di luar jangkauan. Yang dijaga: pemindahan tidak mengubah apa pun.
 *
 * @coversNothing
 */
class ExportHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        require_once MEEL_ROOT . '/modules/core/helpers/export.php';
        require_once MEEL_ROOT . '/admin/includes/export_columns.php';
    }

    /**
     * Spesifikasi diambil dari BERKAS YANG SAMA dengan yang dipakai halaman,
     * bukan disalin ke sini. Kalau test menyalinnya, perubahan di halaman
     * tidak akan terlihat -- test hanya memverifikasi salinannya sendiri.
     */
    private function uqCols(): array
    {
        return meel_export_cols_upload_queue();
    }

    private function logCols(): array
    {
        return meel_export_cols_activity_log();
    }

    private function csvOf(array $vals): string
    {
        $o = fopen('php://memory', 'w+');
        fputcsv($o, $vals);
        rewind($o);
        $s = (string) stream_get_contents($o);
        fclose($o);
        return $s;
    }

    /**

     * Halaman harus benar-benar memakai spesifikasi dari berkas bersama itu,
     * bukan mendefinisikan ulang daftarnya sendiri. Kalau halaman mengulang
     * definisinya, spec yang diuji test dan yang dipakai produksi bisa berbeda --
     * persis kelas bug yang pemisahan ini harus hilangkan.
     */
    public function testPageLoadsTheSharedSpecInsteadOfRedefiningIt(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/admin/activity_log.php');

        $this->assertStringContainsString(
            "require_once __DIR__ . '/includes/export_columns.php'",
            $src,
            'admin/activity_log.php tidak memuat admin/includes/export_columns.php'
        );
        $this->assertStringContainsString(
            'meel_export_cols_upload_queue()',
            $src,
            'Halaman tidak memakai meel_export_cols_upload_queue()'
        );
        $this->assertStringContainsString(
            'meel_export_cols_activity_log()',
            $src,
            'Halaman tidak memakai meel_export_cols_activity_log()'
        );

        $this->assertSame(
            0,
            substr_count($src, "\$uq_export_cols = ["),
            'Spesifikasi antrean unggah jangan didefinisikan literal di halaman lagi'
        );
        $this->assertSame(
            0,
            substr_count($src, "\$log_export_cols = ["),
            'Spesifikasi log aktivitas jangan didefinisikan literal di halaman lagi'
        );

    }

    /**
     * Bekas duplikasi harus benar-benar hilang -- bukan hanya disembunyikan
     * di balik helper sementara daftar kolomnya masih ditulis ulang inline.
     */
    public function testInlineColumnArraysAreGone(): void
    {
        $src = (string) file_get_contents(MEEL_ROOT . '/admin/activity_log.php');

        $this->assertSame(
            0,
            preg_match_all("/\[\s*'ID'\s*,\s*'User'\s*,\s*'URL'\s*,\s*'Tipe'\s*,\s*'Status'/", $src),
            'Daftar header antrean unggah masih ditulis inline'
        );
        $this->assertSame(
            0,
            preg_match_all("/\[\s*'ID'\s*,\s*'User ID'\s*,\s*'Username'\s*,\s*'Action'/", $src),
            'Daftar header log aktivitas masih ditulis inline'
        );

        $this->assertSame(
            0,
            preg_match_all("/\[\s*'ID'\s*,\s*'User'\s*,\s*'URL'\s*,\s*'Tipe'/", $src),
            'Daftar header antrean unggah ditulis inline dengan spasi berbeda'
        );

        $this->assertSame(
            0,
            substr_count($src, 'urn:schemas-microsoft-com:office:spreadsheet'),
            'Premable SpreadsheetML masih ditulis inline; harus lewat meel_xls_begin()'
        );
        $this->assertSame(
            0,
            substr_count($src, '$xls_cell'),
            'Closure $xls_cell masih ada; harus lewat meel_xls_cell()'
        );
    }

    public function testLabelsMatchTheOldHardcodedHeaders(): void
    {
        $this->assertSame(
            ['ID', 'User', 'URL', 'Tipe', 'Status', 'Waktu'],
            meel_export_labels($this->uqCols())
        );
        $this->assertSame(
            ['ID', 'User ID', 'Username', 'Action', 'Media Type', 'Media ID', 'Alamat IP', 'Waktu'],
            meel_export_labels($this->logCols())
        );
    }

    /**
     * `??` punya semantik yang tak boleh dilupakan: kunci yang tidak ada
     * DAN kunci bernilai null KEDUANYA menghasilkan default. Kolom tanpa
     * `default` harus meneruskan null apa adanya.
     */
    public function testNullAndMissingKeysFollowTheOldCoalesceSemantics(): void
    {
        $lengkap = [
            'id' => '7', 'username' => 'dafa', 'url' => '/w', 'media_type' => 'video',
            'status' => 'done', 'created_at' => '2026-10-14 10:00:00',
        ];
        $nilaiNull = array_map(static fn($v) => null, $lengkap);
        $hilang = [];

        $this->assertSame(
            ['7', 'dafa', '/w', 'video', 'done', '2026-10-14 10:00:00'],
            meel_export_values($lengkap, $this->uqCols()),
            'Baris lengkap harus diteruskan apa adanya'
        );

        $expectedNull = [null, '—', '', '', '', null];
        $this->assertSame(
            $expectedNull,
            meel_export_values($nilaiNull, $this->uqCols()),
            'Kolom dengan default harus memakai `??`: null DAN hilang -> default. '
            . 'Kolom tanpa default (id, created_at) harus meneruskan null.'
        );

        $this->assertSame(
            $expectedNull,
            meel_export_values($hilang, $this->uqCols()),
            'Kunci yang hilang berperilaku sama dengan null pada kolom ber-default'
        );
    }

    /**
     * Perbedaan yang paling mudah hilang: JSON lama memakai
     * `$r[$key] !== null ? (int)$r[$key] : null`. Kalau `default` ikut
     * diterapkan lebih dulu, null jadi `''` lalu jadi `0` -- dan JSON yang
     * tadinya melaporkan `"user_id": null` tiba-tiba melaporkan `0`.
     *
     * null berarti "tidak diketahui", 0 berarti "user 0". Itu bukan detail
     * kecil untuk data yang dipakai menghitung siapa yang melakukan apa.
     */
    public function testNullableIntKeepsNullInsteadOfCollapsingToZero(): void
    {
        $row = [
            'id' => '1', 'user_id' => null, 'username' => null, 'action' => 'upload',
            'media_type' => 'video', 'media_id' => null, 'ip_address' => '127.0.0.1',
            'created_at' => '2026-10-14 09:00:00',
        ];

        $map = meel_export_map($row, $this->logCols());

        $this->assertNull($map['user_id'], 'user_id null harus tetap null, bukan 0');
        $this->assertNull($map['media_id'], 'media_id null harus tetap null, bukan 0');

        $tanpaDefault = ['action' => null] + $row;
        $this->assertNull(
            meel_export_map($tanpaDefault, $this->logCols())['action'],
            'Kolom tanpa default harus meneruskan null, bukan mengganti dengan default'
        );

        $angka = $row;
        $angka['user_id'] = '42';
        $angka['media_id'] = '7';
        $mapAngka = meel_export_map($angka, $this->logCols());
        $this->assertSame(42, $mapAngka['user_id']);
        $this->assertSame(7, $mapAngka['media_id']);

        $this->assertSame(0, meel_export_map(['id' => null] + $row, $this->logCols())['id']);
    }

    /**
     * Bentuk JSON harus sama persis dengan peta yang ditulis inline
     * sebelumnya -- termasuk kunci dan urutannya.
     */
    public function testJsonShapeMatchesTheOldInlineArray(): void
    {
        $row = [
            'id' => '5', 'user_id' => '9', 'username' => 'sari', 'action' => 'edit',
            'media_type' => 'book', 'media_id' => '3', 'ip_address' => '10.1.1.1',
            'created_at' => '2026-10-14 08:00:00',
        ];

        $expected = [
            'id' => 5,
            'user_id' => 9,
            'username' => 'sari',
            'action' => 'edit',
            'media_type' => 'book',
            'media_id' => 3,
            'ip_address' => '10.1.1.1',
            'created_at' => '2026-10-14 08:00:00',
        ];

        $map = meel_export_map($row, $this->logCols());
        $this->assertSame($expected, $map);
        $this->assertSame(array_keys($expected), array_keys($map), 'Urutan kunci JSON harus sama');
    }

    public function testCsvValuesMatchTheOldInlineArray(): void
    {
        $row = [
            'id' => '5', 'username' => 'dafa', 'url' => '/watch?v=1&t=2',
            'media_type' => 'video', 'status' => 'done', 'created_at' => '2026-10-14 10:00:00',
        ];

        $expected = [$row['id'], $row['username'] ?? '—', $row['url'] ?? '',
                      $row['media_type'] ?? '', $row['status'] ?? '', $row['created_at']];

        $this->assertSame(
            $this->csvOf($expected),
            $this->csvOf(meel_export_values($row, $this->uqCols())),
            'Baris CSV harus identik, termasuk kutip fputcsv di sekitar yang berisi koma'
        );
    }

    /**
     * Keamanan: nilai apa pun yang masuk ke SpreadsheetML harus di-escape.
     * Username, judul, dan URL berasal dari pengguna. Tanpa
     * `htmlspecialchars()`, satu nilai berisi `<` merusak dokumen XML --
     * dan pada kasus terburuk menyisipkan markup ke dalam file yang dibuka
     * Excel.
     */
    public function testXlsCellsAreEscaped(): void
    {
        $cell = meel_xls_cell('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $cell);
        $this->assertStringContainsString('&lt;script&gt;', $cell);
    }

    public function testXlsEmptyAndNumericCellsMatchTheOldClosure(): void
    {

        $this->assertStringContainsString(
            '<Data ss:Type="String"></Data></Cell>',
            meel_xls_cell(null)
        );
        $this->assertStringContainsString(
            '<Data ss:Type="String"></Data></Cell>',
            meel_xls_cell('')
        );

        $num = meel_xls_cell('42', 'Number');
        $this->assertStringContainsString('ss:Type="Number"', $num);
        $this->assertStringContainsString('>42<', $num);
    }

    public function testXlsRowUsesColumnTypesForNumericCells(): void
    {
        $row = [
            'id' => '7', 'username' => 'dafa', 'url' => '/w', 'media_type' => 'video',
            'status' => 'done', 'created_at' => '2026-10-14 10:00:00',
        ];

        ob_start();
        meel_xls_data_row(meel_export_values($row, $this->uqCols()), $this->uqCols());
        $out = (string) ob_get_clean();

        $this->assertStringContainsString('<Data ss:Type="Number">7</Data>', $out);
        $this->assertStringContainsString('<Data ss:Type="String">dafa</Data>', $out);
        $this->assertSame(6, substr_count($out, '<Cell>'), 'Satu sel per kolom');
    }

    public function testXlsDocumentIsWellFormed(): void
    {
        ob_start();
        meel_xls_begin('Antrean Unggah');
        meel_xls_header(['ID', 'User']);
        meel_xls_end();
        $xml = (string) ob_get_clean();

        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);

        $this->assertNotFalse(
            $doc,
            "Keluaran SpreadsheetML harus XML yang valid.\n" . $xml
        );

        $ss = $doc->children('urn:schemas-microsoft-com:office:spreadsheet');
        $this->assertNotNull($ss->Worksheet, 'Elemen Worksheet tidak ditemukan pada namespace ss');
        $this->assertSame(
            'Antrean Unggah',
            (string) $ss->Worksheet->attributes('ss', true)->Name,
            'Nama worksheet harus tersimpan di atribut, bukan di teks'
        );
    }

    /**
     * Nama worksheet ikut di-escape. Dulu `ss:Name` ditulis mentah dengan
     * asumsi hard-coded, jadi tidak terlihat sebagai celah.
     */
    public function testXlsSheetNameIsEscaped(): void
    {
        ob_start();
        meel_xls_begin('A & B <script>');
        meel_xls_end();
        $xml = (string) ob_get_clean();

        $this->assertStringNotContainsString('<script>', $xml);
        $this->assertStringContainsString('&amp;', $xml);
    }
}

/* reference build: MEeL-W3Q7MJ5 [export-helpers] */