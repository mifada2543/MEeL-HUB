<?php

/**
 * Helper ekspor data: CSV, JSON, dan SpreadsheetML (format yang dibaca Excel
 * dari berkas berekstensi ".xls").
 *
 * MENGAPA ADA
 * -----------
 * `admin/activity_log.php` dulunya punya DUA blok ekspor dan total ENAM definisi
 * pemetaan baris yang sama persis. Yang berduplikasi:
 *
 *   - premable XML SpreadsheetML (9 baris)  -> 2 salinan, byte-identik
 *   - closure $xls_cell                      -> 2 salinan, byte-identik
 *   - header upload queue ['ID','User',...] -> 4 salinan
 *   - pemetaan 6 kolom baris upload queue   -> 6 salinan (csv/json/xls,
 *                                             masing-masing untuk unduhan
 *                                             dan pratinjau)
 *
 * Konsekuensinya bukan cuma berkas panjang. Kalau satu kolom diubah --
 * ditambah, diurutkan ulang, atau diganti label -- ada lima tempat lain
 * yang HARUS ikut berubah, dan tidak ada yang memberi tahu kalau lupa.
 * Ekspor CSV bisa diam-diam punya kolom berbeda dari ekspor JSON.
 *
 * Yang dikumpulkan di sini adalah BENTUR, bukan isi data. Daftar kolom
 * milik tiap dataset tetap didefinisikan di halaman yang memakainya,
 * karena itu domain data, bukan utilitas.
 *
 * @see admin/activity_log.php
 */

/* ========================================================================
 * SpreadsheetML (.xls)
 * ===================================================================== */

if (!function_exists('meel_xls_begin')) {
    /**
     * Buka dokumen SpreadsheetML: preamble, properti dokumen, dan pembuka
     * tabel. Header HTTP dan penutup dokumen ditangani pemanggil.
     */
    function meel_xls_begin(string $sheet_name): void
    {
        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<?mso-application progid="Excel.Sheet"?>' . "\n";
        echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"' . "\n";
        echo '  xmlns:o="urn:schemas-microsoft-com:office:office"' . "\n";
        echo '  xmlns:x="urn:schemas-microsoft-com:office:excel"' . "\n";
        echo '  xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' . "\n";
        echo '  <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">' . "\n";
        echo '    <Author>MEeL Admin</Author>' . "\n";
        echo '    <Created>' . date('c') . '</Created>' . "\n";
        echo '  </DocumentProperties>' . "\n";
        echo '  <Worksheet ss:Name="' . htmlspecialchars($sheet_name) . '">' . "\n";
        echo '    <Table>' . "\n";
    }
}

if (!function_exists('meel_xls_header')) {
    /** Baris header. */
    function meel_xls_header(array $labels): void
    {
        echo '      <Row>' . "\n";
        foreach ($labels as $label) {
            echo '        <Cell><Data ss:Type="String">' . htmlspecialchars((string) $label) . '</Data></Cell>' . "\n";
        }
        echo '      </Row>' . "\n";
    }
}

if (!function_exists('meel_xls_cell')) {
    /**
     * Satu sel. Semua nilai string wajib melewati `htmlspecialchars()` --
     * tanpa itu, username atau judul video yang berisi `<`/`&` akan
     * merusak dokumen XML (atau, pada kasus terburuk, menyisipkan markup).
     */
    function meel_xls_cell($value, string $type = 'String'): string
    {
        if ($value === null || $value === '') {
            return '        <Cell><Data ss:Type="String"></Data></Cell>' . "\n";
        }
        if ($type === 'Number') {
            return '        <Cell><Data ss:Type="Number">' . (int) $value . '</Data></Cell>' . "\n";
        }
        return '        <Cell><Data ss:Type="String">' . htmlspecialchars((string) $value) . '</Data></Cell>' . "\n";
    }
}

if (!function_exists('meel_xls_data_row')) {
    /**
     * Satu baris data, dengan tipe sel diambil dari spesifikasi kolom.
     * Nilai harus urut sesuai dengan `meel_export_values()`.
     */
    function meel_xls_data_row(array $values, array $cols): void
    {
        echo '      <Row>' . "\n";
        foreach ($values as $i => $value) {
            $type = isset($cols[$i]['type']) && str_starts_with((string) $cols[$i]['type'], 'int')
                ? 'Number'
                : 'String';
            echo meel_xls_cell($value, $type);
        }
        echo '      </Row>' . "\n";
    }
}

if (!function_exists('meel_xls_end')) {
    /** Penutup tabel, worksheet, dan workbook. */
    function meel_xls_end(): void
    {
        echo '    </Table>' . "\n";
        echo '  </Worksheet>' . "\n";
        echo '</Workbook>' . "\n";
    }
}

/* ========================================================================
 * Spesifikasi kolom
 *
 * Bentuk satu entri:
 *   [
 *     'key'     => kunci di baris hasil query,
 *     'label'   => teks header (dipakai CSV, XLS, dan pratinjau),
 *     'default' => OPSIONAL. Kalau ada, dipakai sebagai `$row[$key] ?? default`,
 *                 jadi null juga ikut berganti ke default. Kalau TIDAK ada,
 *                 nilai diteruskan apa adanya -- null tetap null.
 *     'type'    => 'int'  -> selalu di Casting ke int
 *                 'int?'  -> int, TETAPI null tetap null (baca nilai mentah)
 *   ]
 * ===================================================================== */

if (!function_exists('meel_export_labels')) {
    /** @return string[] label header, sesuai urutan kolom */
    function meel_export_labels(array $cols): array
    {
        return array_map(static fn(array $c): string => (string) $c['label'], $cols);
    }
}

if (!function_exists('meel_export_values')) {
    /**
     * Nilai per baris, sesuai urutan kolom. Dipakai CSV dan XLS.
     *
     * `??` di sini bukan sekadar kenyamanan -- itu SEMANTIKA ASLI kode
     * yang digantikan: kolom dengan `default` memakai `$row[$key] ?? default`,
     * jadi kunci yang tidak ada DAN kunci bernilai null keduanya
     * menghasilkan default. Kolom tanpa `default` meneruskan null apa
     * adanya.
     *
     * @return array<int, mixed>
     */
    function meel_export_values(array $row, array $cols): array
    {
        $out = [];
        foreach ($cols as $c) {
            $out[] = $row[$c['key']] ?? ($c['default'] ?? null);
        }
        return $out;
    }
}

if (!function_exists('meel_export_map')) {
    /**
     * Bentuk asosiatif untuk keluaran JSON: kunci = key kolom, nilai sudah
     * di Casting sesuai `type`.
     *
     * Bedanya dari `meel_export_values()` ada di kolom `int?`: JSON asli
     * memakai `$r[$key] !== null ? (int)$r[$key] : null`, jadi null HARUS
     * tetap null. Kalau `default` ikut diterapkan lebih dulu, null berubah
     * jadi `''` lalu jadi `0` -- dan JSON yang tadinya `"user_id": null`
     * tiba-tiba melaporkan `"user_id": 0`. Itu bukan detail kecil: null
     * berarti "tidak diketahui", 0 berarti "user 0".
     *
     * Karena itu `int?` membaca nilai mentah, bukan hasil `??`.
     *
     * @return array<string, mixed>
     */
    function meel_export_map(array $row, array $cols): array
    {
        $out = [];
        foreach ($cols as $c) {
            $type = $c['type'] ?? 'string';
            $raw = $row[$c['key']] ?? null;

            if ($type === 'int?') {
                $out[$c['key']] = $raw === null ? null : (int) $raw;
            } elseif ($type === 'int') {
                $out[$c['key']] = (int) ($row[$c['key']] ?? ($c['default'] ?? null));
            } else {
                $out[$c['key']] = $row[$c['key']] ?? ($c['default'] ?? null);
            }
        }
        return $out;
    }
}

/* reference build: MEeL-E8X2KL5 [export-helpers] */
