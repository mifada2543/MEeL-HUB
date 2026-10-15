<?php

class MediaLibrary
{
    private \mysqli $conn;

    public function __construct(\mysqli $db_connection)
    {
        $this->conn = $db_connection;
    }

    public function getCounts(): array
    {
        $all = meel_media_stats_all($this->conn);
        $counts = ['music' => 0, 'video' => 0, 'books' => 0];
        foreach ($counts as $table => $_) {
            $counts[$table] = $all[$table]['total'] ?? 0;
        }
        return $counts;
    }

    public static function clearCountsCache(): void
    {
        meel_invalidate_media_stats_cache();
    }

    protected function paginateResult(\mysqli_result|null $result, int $total, int $page, int $perPage): array
    {
        $totalPages = max(1, (int)ceil($total / max($perPage, 1)));
        $page = max(1, min($page, $totalPages));

        return [
            'data' => $result,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'from' => ($page - 1) * $perPage + 1,
            'to' => min($page * $perPage, $total),
        ];
    }

    public function getVideosWithMeta(int $page = 1, int $perPage = 15): array
    {
        $offset = ($page - 1) * $perPage;
        $total = $this->countVideos();
        $data = $this->getVideos($perPage, $offset);
        return $this->paginateResult($data, $total, $page, $perPage);
    }

    public function getVideos(int $limit = 15, int $offset = 0)
    {
        $stmt = $this->conn->prepare("SELECT * FROM video ORDER BY upload_date DESC LIMIT ? OFFSET ?");
        $stmt->bind_param("ii", $limit, $offset);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function countVideos(): int
    {
        return meel_media_stats($this->conn, 'video')['total'];
    }

    private function searchMedia(array $cfg): ?\mysqli_result
    {
        $table = $cfg['table'];
        $alias = $cfg['alias'];
        $matchCols = $cfg['matchCols'];
        $selectCols = $cfg['selectCols'];
        $sessionKey = $cfg['sessionKey'];
        $q = $cfg['q'];
        $exclude = $cfg['exclude'];
        $sidebar = $cfg['sidebar'];
        $offset = $cfg['offset'];
        $limit = $cfg['limit'];
        $orderBy = $cfg['orderBy'] ?? "{$alias}.id DESC";

        $a = $alias;
        $ua = $cfg['uploaderAlias'] ?? 'uploader_name';

        if (empty($q)) {
            if ($sidebar) {
                $sidebar_limit = 15;
                $picked_ids = meel_pick_random_ids(
                    $this->conn,
                    $table,
                    $sidebar_limit,
                    $exclude,
                    $_SESSION[$sessionKey] ?? [],
                    $sessionKey
                );

                if (empty($picked_ids)) {
                    $stmt = $this->conn->prepare(
                        "SELECT {$selectCols} FROM {$table} {$a}
                         JOIN users u ON {$a}.user_id = u.id WHERE 1 = 0"
                    );
                } else {
                    $placeholders = implode(',', array_fill(0, count($picked_ids), '?'));
                    $types = str_repeat('i', count($picked_ids));
                    $sql = "SELECT {$selectCols} FROM {$table} {$a}
                            JOIN users u ON {$a}.user_id = u.id
                            WHERE {$a}.id IN ({$placeholders}) ORDER BY FIELD({$a}.id, {$placeholders})";
                    $all_params = array_merge($picked_ids, $picked_ids);
                    $stmt = $this->conn->prepare($sql);
                    $stmt->bind_param($types . $types, ...$all_params);
                }
            } else {
                $stmt = $this->conn->prepare(
                    "SELECT {$selectCols} FROM {$table} {$a}
                     JOIN users u ON {$a}.user_id = u.id
                     WHERE {$a}.id != ? ORDER BY {$orderBy} LIMIT ? OFFSET ?"
                );
                $stmt->bind_param("iii", $exclude, $limit, $offset);
            }
        } else {
            $matchStr = implode(', ', $matchCols);
            $stmt = $this->conn->prepare(
                "SELECT {$selectCols},
                 MATCH({$matchStr}) AGAINST (? IN BOOLEAN MODE) AS rank
                 FROM {$table} {$a}
                 JOIN users u ON {$a}.user_id = u.id
                 WHERE MATCH({$matchStr}) AGAINST (? IN BOOLEAN MODE) AND {$a}.id != ?
                 ORDER BY rank DESC, {$orderBy} LIMIT ? OFFSET ?"
            );
            $stmt->bind_param("ssiii", $q, $q, $exclude, $limit, $offset);
        }
        try {
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            return null;
        }
        $result = $stmt->get_result();
        return $result ?: null;
    }

    private function countSearchMedia(array $cfg): int
    {
        $table = $cfg['table'];
        $alias = $cfg['alias'];
        $matchCols = $cfg['matchCols'];
        $q = $cfg['q'];
        $exclude = $cfg['exclude'];
        $a = $alias;

        if (empty($q)) {
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) AS total FROM {$table} {$a}
                 JOIN users u ON {$a}.user_id = u.id
                 WHERE {$a}.id != ?"
            );
            $stmt->bind_param("i", $exclude);
        } else {
            $matchStr = implode(', ', $matchCols);
            $stmt = $this->conn->prepare(
                "SELECT COUNT(*) AS total FROM {$table} {$a}
                 JOIN users u ON {$a}.user_id = u.id
                 WHERE MATCH({$matchStr}) AGAINST (? IN BOOLEAN MODE)
                   AND {$a}.id != ?"
            );
            $stmt->bind_param("si", $q, $exclude);
        }

        try {
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            return 0;
        }
        $res = $stmt->get_result();
        return $res ? (int)$res->fetch_assoc()['total'] : 0;
    }

    public function searchVideo(string $q, int $exclude = 0, bool $sidebar = false, int $offset = 0, int $fetchLimit = 21)
    {
        return $this->searchMedia([
            'table' => 'video',
            'alias' => 'v',
            'selectCols' => 'v.*, u.username AS uploader_name',
            'uploaderAlias' => 'uploader_name',
            'matchCols' => ['v.title', 'v.search_metadata'],
            'sessionKey' => 'seen_video_ids',
            'orderBy' => 'v.upload_date DESC',
            'q' => $q,
            'exclude' => $exclude,
            'sidebar' => $sidebar,
            'offset' => $offset,
            'limit' => $fetchLimit,
        ]);
    }

    public function countSearchVideo(string $q, int $exclude = 0): int
    {
        return $this->countSearchMedia([
            'table' => 'video',
            'alias' => 'v',
            'matchCols' => ['v.title', 'v.search_metadata'],
            'q' => $q,
            'exclude' => $exclude,
        ]);
    }

    public function getMusicListWithMeta(string $format = 'all', string $artist = 'all', int $page = 1, int $perPage = 10): array
    {
        $offset = ($page - 1) * $perPage;
        $total = $this->countMusic($format, $artist);
        $data = $this->getMusicList($format, $artist, $perPage, $offset);
        return $this->paginateResult($data, $total, $page, $perPage);
    }

    public function getMusicList(string $format = 'all', string $artist = 'all', int $limit = 10, int $offset = 0)
    {
        $data = $this->buildMusicWhere($format, $artist);
        $stmt = $this->conn->prepare("SELECT * FROM music WHERE {$data['where']} ORDER BY id DESC LIMIT ? OFFSET ?");

        $params = array_merge($data['params'], [$limit, $offset]);
        $types = $data['types'] . "ii";

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result();
    }

    public function countMusic(string $format = 'all', string $artist = 'all'): int
    {
        $data = $this->buildMusicWhere($format, $artist);
        $stmt = $this->conn->prepare("SELECT COUNT(*) AS total FROM music WHERE {$data['where']}");

        if (!empty($data['params'])) {
            $stmt->bind_param($data['types'], ...$data['params']);
        }

        $stmt->execute();
        $res = $stmt->get_result();
        return (int)$res->fetch_assoc()['total'];
    }

    public function getArtists()
    {
        return $this->conn->query("SELECT DISTINCT artist FROM music WHERE artist != '' ORDER BY artist ASC");
    }

    public function getUserPlaylists(int $user_id)
    {
        $stmt = $this->conn->prepare("SELECT * FROM playlists WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        return $stmt->get_result();
    }

    public static function playlistSlug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');
        return $slug !== '' ? $slug : 'playlist';
    }

    private const RESERVED_MUSIC_ROUTES = [
        'index', 'beranda', 'watch', 'upload', 'search', 'load-more', 'stream',
        'file', 'playlist', 'playlist-action',
    ];

    public function getUserPlaylistRoutes(int $user_id): array
    {
        $routes = [];
        $used = [];
        $res = $this->conn->query("SELECT id, name FROM playlists WHERE user_id = " . (int) $user_id . " ORDER BY id ASC");
        while ($row = $res->fetch_assoc()) {
            $id = (int) $row['id'];
            $base = self::playlistSlug((string) $row['name']);
            $slug = $base;
            if (isset($used[$slug]) || in_array($slug, self::RESERVED_MUSIC_ROUTES, true)) {
                $slug = $base . '-' . $id;
            }
            $used[$slug] = true;
            $routes[$id] = $slug;
        }
        return $routes;
    }

    public function resolvePlaylistSlug(string $slug, int $user_id): int
    {
        foreach ($this->getUserPlaylistRoutes($user_id) as $id => $route) {
            if ($route === $slug) {
                return $id;
            }
        }
        return 0;
    }

    public function searchMusic(string $q, int $exclude = 0, bool $sidebar = false, int $offset = 0, int $fetchLimit = 21)
    {
        return $this->searchMedia([
            'table' => 'music',
            'alias' => 'm',
            'selectCols' => 'm.*, u.username AS uploader',
            'uploaderAlias' => 'uploader',
            'matchCols' => ['m.title', 'm.artist', 'm.search_metadata'],
            'sessionKey' => 'seen_music_ids',
            'orderBy' => 'm.id DESC',
            'q' => $q,
            'exclude' => $exclude,
            'sidebar' => $sidebar,
            'offset' => $offset,
            'limit' => $fetchLimit,
        ]);
    }

    public function countSearchMusic(string $q, int $exclude = 0): int
    {
        return $this->countSearchMedia([
            'table' => 'music',
            'alias' => 'm',
            'matchCols' => ['m.title', 'm.artist', 'm.search_metadata'],
            'q' => $q,
            'exclude' => $exclude,
        ]);
    }

    private function buildMusicWhere(string $format, string $artist): array
    {
        $allowed_formats = ['mp3', 'ogg', 'm4a', 'opus', 'flac', 'wav'];
        $parts = ["1=1"];
        $params = [];
        $types = "";

        if ($format !== 'all' && in_array($format, $allowed_formats, true)) {
            $parts[] = "filename LIKE ?";
            $params[] = "%.$format";
            $types .= "s";
        }

        if ($artist !== 'all' && !empty($artist)) {
            $parts[] = "artist = ?";
            $params[] = $artist;
            $types .= "s";
        }

        return [
            'where' => implode(' AND ', $parts),
            'params' => $params,
            'types' => $types
        ];
    }
}

/* reference build: MEeL-C10H12N2O [cd2f94e2f90c8a6f] */