<?php
/**
 * Pola pemeriksaan keamanan untuk orkestrasi transcoder.
 *
 * Pola-pola ini mengikat ke bentuk kode, bukan ke perilakunya: begitu
 * sebuah method dipecah dan variabelnya berubah nama, polanya tidak lagi
 * cocok — dan gerbang akan melaporkannya sebagai kegagalan. Yang buruk
 * adalah kalau pola itu dipoles diam-diam supaya cocok lagi: gerbang tetap
 * hijau, tapi tidak ada yang memeriksa apa pun.
 *
 * Dipisah ke berkas sendiri supaya unit test bisa memuat peta yang sama dan
 * memastikan SETIAP pola masih benar-benar cocok dengan kode hari ini.
 */

/**
 * @return array<string, string> nama pemeriksaan => pola regex
 */
function meel_transcoder_check_patterns(): array
{
    return [

        'proc_open array (finalizeVideo)' => '/\$hls_cmd\s*=\s*\[\s*\$this->ffmpeg_bin/',

        'proc_open menerima array, bukan string shell' => "/proc_open\(\\\$job\['cmd'\]/",

        'proc_open array (transcodeVideo)' => '/proc_open\(\$tc_cmd/',
        'env vars via $env (LD_LIBRARY_PATH)' => "/'LD_LIBRARY_PATH'/",
        'env vars via $env (PATH)' => "/'PATH'/",
        'putenv() untuk processDownload' => "/putenv\('PATH/",

        'stderr pipe untuk progress FFmpeg' => '/\$stream\s*=\s*\$pipes\[2\]/',
        'baca progress dari stream stderr' => '/fgets\(\$stream\)/',

        'marker file anti-duplikat transcode' => '/marker_file/',
        'folder naming lock' => '/meel_transcode_folder\.lock/',
    ];
}
