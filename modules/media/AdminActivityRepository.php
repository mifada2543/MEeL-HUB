<?php
require_once __DIR__ . '/AdminFilteredRepository.php';

class AdminActivityRepository extends AdminFilteredRepository
{
    protected function tableName(): string
    {
        return 'activity_log';
    }

    protected function tableAlias(): string
    {
        return 'al';
    }

    protected function applyPrimaryFilter(string $value): void
    {
        if ($value === '') {
            return;
        }
        $this->where_conditions[] = 'al.action = ?';
        $this->params[] = $value;
        $this->types .= 's';
    }

    protected function applySearchFilter(string $search_q): void
    {
        if ($search_q === '') {
            return;
        }
        $this->where_conditions[] = '(u.username LIKE ? OR al.ip_address LIKE ?)';
        $search_like = "%{$search_q}%";
        $this->params[] = $search_like;
        $this->params[] = $search_like;
        $this->types .= 'ss';
    }

    public function clearAll(): bool
    {
        return $this->conn->query('TRUNCATE TABLE ' . $this->tableName()) !== false;
    }

    public function getDistinctActions(): array
    {
        $actions = [];
        $res = $this->conn->query('SELECT DISTINCT action FROM activity_log ORDER BY action ASC');
        if ($res) {
            while ($a = $res->fetch_assoc()) {
                $actions[] = $a['action'];
            }
        }
        return $actions;
    }

    public function getWeeklyStats(): array
    {
        $res = $this->conn->query(
            "SELECT COUNT(*) AS total, COUNT(DISTINCT user_id) AS unique_users
             FROM activity_log WHERE created_at >= NOW() - INTERVAL 7 DAY"
        );
        return $res ? $res->fetch_assoc() : ['total' => 0, 'unique_users' => 0];
    }
}

/* reference build: MEeL-C8H11NO2 [aafd49bd13c0c369] */
