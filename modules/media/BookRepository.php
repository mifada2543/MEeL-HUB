<?php

class BookRepository
{
    private \mysqli $conn;

    public function __construct(\mysqli $db_connection)
    {
        $this->conn = $db_connection;
    }

    public function getBooks(string $filter = 'all', int $limit = 0, int $offset = 0)
    {
        $allowed = ['manga', 'pdf'];
        $limitSql = ($limit > 0) ? " LIMIT ? OFFSET ?" : "";

        if (in_array($filter, $allowed, true)) {
            $stmt = $this->conn->prepare(
                "SELECT * FROM books WHERE type = ? ORDER BY upload_date DESC{$limitSql}"
            );
            if ($limit > 0) {
                $stmt->bind_param("sii", $filter, $limit, $offset);
            } else {
                $stmt->bind_param("s", $filter);
            }
        } else {
            $stmt = $this->conn->prepare(
                "SELECT * FROM books ORDER BY upload_date DESC{$limitSql}"
            );
            if ($limit > 0) {
                $stmt->bind_param("ii", $limit, $offset);
            }
        }

        $stmt->execute();
        return $stmt->get_result();
    }

    public function countBooks(string $filter = 'all'): int
    {
        $allowed = ['manga', 'pdf'];

        if (in_array($filter, $allowed, true)) {
            $stmt = $this->conn->prepare("SELECT COUNT(*) AS total FROM books WHERE type = ?");
            $stmt->bind_param("s", $filter);
        } else {
            $stmt = $this->conn->prepare("SELECT COUNT(*) AS total FROM books");
        }

        $stmt->execute();
        return (int)$stmt->get_result()->fetch_assoc()['total'];
    }

    public function searchBooks(string $q, string $type = 'all', int $offset = 0, int $limit = 24)
    {
        $allowed = ['manga', 'pdf'];
        $type_where = in_array($type, $allowed, true) ? " AND type = ?" : "";

        if (empty($q)) {
            $stmt = $this->conn->prepare(
                "SELECT * FROM books WHERE 1=1{$type_where} ORDER BY upload_date DESC LIMIT ? OFFSET ?"
            );
            if ($type_where !== '') {
                $stmt->bind_param("sii", $type, $limit, $offset);
            } else {
                $stmt->bind_param("ii", $limit, $offset);
            }
        } else {
            $stmt = $this->conn->prepare(
                "SELECT *, MATCH(title, author) AGAINST (? IN BOOLEAN MODE) AS rank
                 FROM books
                 WHERE MATCH(title, author) AGAINST (? IN BOOLEAN MODE){$type_where}
                 ORDER BY rank DESC, upload_date DESC
                 LIMIT ? OFFSET ?"
            );
            if ($type_where !== '') {
                $stmt->bind_param("sssii", $q, $q, $type, $limit, $offset);
            } else {
                $stmt->bind_param("ssii", $q, $q, $limit, $offset);
            }
        }

        try {
            $stmt->execute();
        } catch (\mysqli_sql_exception $e) {
            return null;
        }
        $result = $stmt->get_result();
        return $result ?: null;
    }

    public function getBooksPaginated(string $filter = 'all', int $page = 1, int $perPage = 24): array
    {
        $total = $this->countBooks($filter);
        $totalPages = max(1, (int)ceil($total / max($perPage, 1)));
        $page = max(1, min($page, $totalPages));
        $offset = ($page - 1) * $perPage;
        $data = $this->getBooks($filter, $perPage, $offset);

        return [
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
            'from' => $offset + 1,
            'to' => min($page * $perPage, $total),
        ];
    }

    public function getBookById(int $id): ?array
    {
        $stmt = $this->conn->prepare("SELECT * FROM books WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return ($result && $result->num_rows > 0) ? $result->fetch_assoc() : null;
    }

    public function getUserRole(int $user_id): ?string
    {
        return get_user_role($this->conn, $user_id);
    }
}

/* reference build: MEeL-C10H12N2O [cd2f94e2f90c8a6f] */
