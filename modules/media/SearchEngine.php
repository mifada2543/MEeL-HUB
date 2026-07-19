<?php
/**
 * modules/SearchEngine.php
 *
 * SearchEngine — Centralized search query handler.
 * Mem-parsing parameter request dan mengembalikan hasil search
 * dalam format terstandarisasi, sehingga file search_video.php /
 * search_music.php hanya perlu fokus ke rendering.
 *
 * @package MEeL
 */

require_once __DIR__ . '/MediaLibrary.php';

class SearchEngine
{
    private mysqli $conn;
    private MediaLibrary $library;

    // Limit default, harus sinkron dengan @ MediaLibrary
    const VIDEO_LIMIT = 20;
    const MUSIC_LIMIT = 20;

    public function __construct(mysqli $db_connection)
    {
        $this->conn    = $db_connection;
        $this->library = new MediaLibrary($db_connection);
    }

    // ── PARAMETER PARSING ───────────────────────────────────────────────────

    /**
     * Parse parameter request yang umum dipakai di semua halaman search.
     *
     * @return array{
     *   query:    string,
     *   exclude:  int,
     *   offset:   int,
     *   sidebar:  bool,
     *   target:   string,
     * }
     */
    public function parseParams(): array
    {
        return [
            'query'   => trim($_GET['search'] ?? ''),
            'exclude' => isset($_GET['exclude']) ? (int)$_GET['exclude'] : 0,
            'offset'  => isset($_GET['offset']) ? max(0, (int)$_GET['offset']) : 0,
            'sidebar' => $this->detectSidebar(),
            'target'  => $_SERVER['HTTP_HX_TARGET'] ?? '',
        ];
    }

    /**
     * Deteksi apakah request berasal dari sidebar HTMX (recommendation).
     */
    private function detectSidebar(): bool
    {
        $target = $_SERVER['HTTP_HX_TARGET'] ?? '';
        return in_array($target, ['recommendation-column', 'music-recommendation-column'], true);
    }

    // ── SEARCH RESULT BUILDER ────────────────────────────────────────────────

    /**
     * Bungkus mysqli_result ke array asosiatif + metadata.
     */
    private function buildResult(\mysqli_result $data, array $params, int $limit): array
    {
        $rows = [];
        while ($row = $data->fetch_assoc()) {
            $rows[] = $row;
        }

        return [
            'results' => $rows,
            'count'   => count($rows),
            'limit'   => $limit,
            'offset'  => $params['offset'],
            'hasMore' => count($rows) >= $limit,
            'sidebar' => $params['sidebar'],
            'query'   => $params['query'],
            'exclude' => $params['exclude'],
        ];
    }

    // ── VIDEO SEARCH ─────────────────────────────────────────────────────────

    /**
     * Cari video — delegasi ke MediaLibrary::searchVideo(),
     * lalu bungkus dalam format terstandarisasi.
     */
    public function searchVideo(array $params): array
    {
        $data = $this->library->searchVideo(
            $params['query'],
            $params['exclude'],
            $params['sidebar'],
            $params['offset']
        );

        return $this->buildResult($data, $params, self::VIDEO_LIMIT);
    }

    // ── MUSIC SEARCH ─────────────────────────────────────────────────────────

    /**
     * Cari musik — delegasi ke MediaLibrary::searchMusic(),
     * lalu bungkus dalam format terstandarisasi.
     */
    public function searchMusic(array $params): array
    {
        $data = $this->library->searchMusic(
            $params['query'],
            $params['exclude'],
            $params['sidebar']
        );

        return $this->buildResult($data, $params, self::MUSIC_LIMIT);
    }
}
