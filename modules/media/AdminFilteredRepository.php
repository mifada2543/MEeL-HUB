<?php

/**
 * Basis untuk repository admin yang memfilter baris berdasarkan rentang
 * waktu.
 *
 * Dua repository di panel admin — log aktivitas dan antrean unggah —
 * punya query yang nyaris sama: filter waktu, pencarian, hitung, halaman,
 * ekspor, dan pembersihan. Yang membedakan hanya nama tabel, alias-nya,
 * serta dua kolom yang disaring.
 *
 * Sebelumnya setiap repository menyalin seluruh query itu. Ketika salah
 * satu diubah — misalnya batas halaman atau urutan pengurutan — salinannya
 * diam-diam tertinggal. Query yang benar-benar berbeda tetap milik
 * masing-masing subclass.
 *
 * Subclass wajib menyediakan:
 *  - `tableName()` — nama tabel
 *  - `tableAlias()` — alias tabel di query
 *  - `applyPrimaryFilter()` — kondisi filter pertama
 *  - `applySearchFilter()` — kondisi pencarian
 */
abstract class AdminFilteredRepository
{
    protected \mysqli $conn;

    protected array $where_conditions = ['1=1'];

    protected array $params = [];

    protected string $types = '';

    protected string $where_sql = '1=1';

    public function __construct(\mysqli $conn)
    {
        $this->conn = $conn;
    }

    abstract protected function tableName(): string;

    abstract protected function tableAlias(): string;

    abstract protected function applyPrimaryFilter(string $value): void;

    abstract protected function applySearchFilter(string $search_q): void;

    public function buildFilter(string $filter, string $search_q, int $days): void
    {
        $this->where_conditions = ['1=1'];
        $this->params = [];
        $this->types = '';

        $this->applyPrimaryFilter($filter);
        $this->applySearchFilter($search_q);

        $this->where_conditions[] = $this->tableAlias() . '.created_at >= NOW() - INTERVAL ? DAY';
        $this->params[] = $days;
        $this->types .= 'i';

        $this->where_sql = implode(' AND ', $this->where_conditions);
    }

    public function countFiltered(): int
    {
        $alias = $this->tableAlias();
        $stmt = $this->conn->prepare(
            "SELECT COUNT(*) AS total FROM {$this->tableName()} {$alias} LEFT JOIN users u ON {$alias}.user_id = u.id WHERE {$this->where_sql}"
        );
        if (!empty($this->params)) {
            $stmt->bind_param($this->types, ...$this->params);
        }
        $stmt->execute();
        $total = (int) $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
        return $total;
    }

    public function fetchPage(int $limit, int $offset)
    {
        $alias = $this->tableAlias();
        $stmt = $this->conn->prepare(
            "SELECT {$alias}.*, u.username
             FROM {$this->tableName()} {$alias}
             LEFT JOIN users u ON {$alias}.user_id = u.id
             WHERE {$this->where_sql}
             ORDER BY {$alias}.created_at DESC
             LIMIT ? OFFSET ?"
        );
        $all_params = array_merge($this->params, [$limit, $offset]);
        $all_types = $this->types . 'ii';
        $stmt->bind_param($all_types, ...$all_params);
        $stmt->execute();
        $result = $stmt->get_result();
        $stmt->close();
        return $result;
    }

    public function fetchAll(int $limit = 50000): array
    {
        $alias = $this->tableAlias();
        $stmt = $this->conn->prepare(
            "SELECT {$alias}.*, u.username
             FROM {$this->tableName()} {$alias}
             LEFT JOIN users u ON {$alias}.user_id = u.id
             WHERE {$this->where_sql}
             ORDER BY {$alias}.created_at DESC
             LIMIT ?"
        );
        $params = $this->params;
        $types = $this->types . 'i';
        $params[] = $limit;
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($r = $result->fetch_assoc()) {
            $rows[] = $r;
        }
        $stmt->close();
        return $rows;
    }

    public function clearOlderThan(int $days): int
    {
        $table = $this->tableName();
        $stmt = $this->conn->prepare("DELETE FROM {$table} WHERE created_at < NOW() - INTERVAL ? DAY");
        $stmt->bind_param('i', $days);
        $stmt->execute();
        $deleted = $stmt->affected_rows;
        $stmt->close();

        $next_id = 1;
        $max_res = $this->conn->query("SELECT MAX(id) AS max_id FROM {$table}");
        if ($max_res) {
            $max_row = $max_res->fetch_assoc();
            $next_id = $max_row['max_id'] ? (int)$max_row['max_id'] + 1 : 1;
            $this->conn->query('ALTER TABLE ' . $table . ' AUTO_INCREMENT = ' . (int)$next_id);
        }
        return $deleted;
    }
}

/* reference build: MEeL-C3H19N6O [admin-filtered-repository] */
