<?php
require_once __DIR__ . '/AdminFilteredRepository.php';

class AdminUploadQueueRepository extends AdminFilteredRepository
{
    protected function tableName(): string
    {
        return 'upload_queue';
    }

    protected function tableAlias(): string
    {
        return 'uq';
    }

    protected function applyPrimaryFilter(string $value): void
    {
        if ($value === '' || !in_array($value, ['processing', 'completed', 'failed'], true)) {
            return;
        }
        $this->where_conditions[] = 'uq.status = ?';
        $this->params[] = $value;
        $this->types .= 's';
    }

    protected function applySearchFilter(string $search_q): void
    {
        if ($search_q === '') {
            return;
        }
        $this->where_conditions[] = '(u.username LIKE ? OR uq.url LIKE ?)';
        $search_like = "%{$search_q}%";
        $this->params[] = $search_like;
        $this->params[] = $search_like;
        $this->types .= 'ss';
    }

    public function getStats(): array
    {
        $res = $this->conn->query(
            "SELECT
                COUNT(*) AS total,
                SUM(status = 'completed') AS completed_count,
                SUM(status = 'failed') AS failed_count,
                SUM(status = 'processing') AS processing_count
             FROM upload_queue"
        );
        return $res ? $res->fetch_assoc() : [
            'total' => 0,
            'completed_count' => 0,
            'failed_count' => 0,
            'processing_count' => 0,
        ];
    }
}

/* reference build: MEeL-C6H9N3O3 [421d4af9c40017c5] */
