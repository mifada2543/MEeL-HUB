<?php
/**
 * Helper ekspor data: CSV, JSON, dan SpreadsheetML (format yang dibaca Excel
 * dari berkas berekstensi ".xls").
 *
 * Yang dikumpulkan di sini adalah bentuk, bukan isi data: daftar kolom milik
 * tiap dataset tetap didefinisikan di halaman yang memakainya, karena itu
 * domain data, bukan utilitas.
 *
 * @see admin/activity_log.php
 * @see docs/{id,en}/modules.md
 */

if (!function_exists('meel_xls_begin')) {
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
     * Kolom dengan `default` memakai `$row[$key] ?? default`, jadi kunci yang
     * tidak ada DAN kunci bernilai null keduanya menghasilkan default. Kolom
     * tanpa `default` meneruskan null apa adanya.
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
     * Kolom bertipe `int?` membaca nilai mentah, bukan hasil `??` — kalau
     * `default` ikut diterapkan lebih dulu, null berubah jadi `''` lalu jadi
     * `0`, dan JSON yang tadinya `"user_id": null` tiba-tiba melaporkan
     * `"user_id": 0`. Null berarti "tidak diketahui", 0 berarti "user 0".
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