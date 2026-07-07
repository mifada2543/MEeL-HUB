<?php
/**
 * GarbageCollector — Otomatis membersihkan file/folder sampah dari direktori temp.
 *
 * File yang sudah tidak disentuh >5 menit (stale) akan dihapus.
 * Berjalan di setiap request halaman upload/transcode agar real-time.
 *
 * Target direktori:
 * - /dev/shm/meel_temp/   (Transcoder RAM disk)
 * - /dev/shm/meel_upload/  (Uploader RAM disk)
 * - {project}/temp/         (fallback disk)
 */

class GarbageCollector
{
    // File/folder lebih dari STALE_SECONDS detik sejak mtime terakhir akan dihapus
    private const STALE_SECONDS = 300; // 5 menit

    // Static flag agar GC hanya 1x per request (dipanggil dari banyak titik)
    private static bool $hasRun = false;

    /**
     * Jalankan garbage collection pada semua direktori temp.
     * Panggil method ini di awal setiap halaman yang memproses upload/transcode.
     * Hanya berjalan 1 kali per request (static flag).
     */
    public static function run(): void
    {
        if (self::$hasRun) return;
        self::$hasRun = true;

        $directories = self::getTargetDirectories();
        if (empty($directories)) return;

        // Batasi eksekusi maksimal 3 detik agar tidak menggangu response user
        $timeout = microtime(true) + 3;

        foreach ($directories as $dir) {
            if (microtime(true) >= $timeout) break;
            self::cleanDirectory($dir);
        }
    }

    /**
     * Kumpulkan semua direktori temp yang ada saat runtime.
     */
    private static function getTargetDirectories(): array
    {
        $dirs = [];

        // 1. Project temp/ fallback
        $project_temp = dirname(__DIR__) . '/temp';
        if (is_dir($project_temp)) {
            $dirs[] = $project_temp;
        }

        // 2. RAM disk Transcoder
        if (is_dir('/dev/shm/meel_temp')) {
            $dirs[] = '/dev/shm/meel_temp';
        }

        // 3. RAM disk Uploader
        if (is_dir('/dev/shm/meel_upload')) {
            $dirs[] = '/dev/shm/meel_upload';
        }

        return $dirs;
    }

    /**
     * Hapus semua file/folder stale di dalam direktori (non-rekursif level-1).
     */
    private static function cleanDirectory(string $dir): void
    {
        $cutoff = time() - self::STALE_SECONDS;

        $items = glob(rtrim($dir, '/') . '/*');
        if (empty($items)) return;

        foreach ($items as $item) {
            $basename = basename($item);

            // ── Skip yt-dlp persistent cache ────────────────────────────────
            if ($basename === 'ytdlp-cache') continue;

            // ── Skip file yang masih baru (mtime dalam 5 menit) ──────────────
            $mtime = @filemtime($item);
            if ($mtime === false || $mtime > $cutoff) continue;

            // ── Hapus file/folder stale ──────────────────────────────────────
            if (is_dir($item)) {
                self::removeDirectory($item);
            } else {
                @unlink($item);
            }
        }
    }

    /**
     * Hapus direktori beserta seluruh isinya secara rekursif.
     */
    private static function removeDirectory(string $dir): void
    {
        $items = glob(rtrim($dir, '/') . '/*');
        if ($items) {
            foreach ($items as $item) {
                is_dir($item) ? self::removeDirectory($item) : @unlink($item);
            }
        }
        @rmdir($dir);
    }
}
