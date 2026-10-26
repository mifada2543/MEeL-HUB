<?php
class MeelCoin
{
    public const HOLD_STALE_SECONDS = 900;
    public const HOLD_TTL_SECONDS = 21600;
    public const CLIENT_HEARTBEAT_MAX_AGE = 120;

    private static ?array $settingsCache = null;

    private static function loadSettings(\mysqli $conn): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        $defaults = [
            'meelcoin_enabled' => '1',
            'meelcoin_upload_cost' => '5',
            'meelcoin_advanced_cost' => '10',
            'meelcoin_transcode_user_cost' => '5',
            'meelcoin_transcode_member_cost' => '2',
            'meelcoin_user_max' => '25',
            'meelcoin_user_refill' => '15',
            'meelcoin_member_max' => '50',
            'meelcoin_member_refill' => '25',
            'meelcoin_refill_hours' => '5',
        ];

        $result = $conn->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'meelcoin_%'");
        $settings = $defaults;
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        }

        self::$settingsCache = $settings;
        return $settings;
    }

    public static function clearCache(): void
    {
        self::$settingsCache = null;
    }

    public static function isEnabled(\mysqli $conn): bool
    {
        $s = self::loadSettings($conn);
        return $s['meelcoin_enabled'] === '1';
    }

    public static function getCost(\mysqli $conn, string $type): int
    {
        $s = self::loadSettings($conn);
        return (int)($type === 'advanced' ? $s['meelcoin_advanced_cost'] : $s['meelcoin_upload_cost']);
    }

    public static function getTranscodeCost(\mysqli $conn, string $role): int
    {
        $s = self::loadSettings($conn);
        return (int)($role === 'member' ? $s['meelcoin_transcode_member_cost'] : $s['meelcoin_transcode_user_cost']);
    }

    public static function getMax(\mysqli $conn, string $role): int
    {
        $s = self::loadSettings($conn);
        if ($role === 'member') return (int)$s['meelcoin_member_max'];
        return (int)$s['meelcoin_user_max'];
    }

    public static function getRefillAmount(\mysqli $conn, string $role): int
    {
        $s = self::loadSettings($conn);
        if ($role === 'member') return (int)$s['meelcoin_member_refill'];
        return (int)$s['meelcoin_user_refill'];
    }

    public static function getRefillHours(\mysqli $conn): int
    {
        $s = self::loadSettings($conn);
        return (int)$s['meelcoin_refill_hours'];
    }

    public static function getBalance(\mysqli $conn, int $userId): int
    {
        $stmt = $conn->prepare("SELECT meelcoin FROM users WHERE id = ?");
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ? (int)$row['meelcoin'] : 0;
    }

    public static function getHeld(\mysqli $conn, int $userId): int
    {
        $stmt = $conn->prepare("SELECT meelcoin_held FROM users WHERE id = ?");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? max(0, (int)$row['meelcoin_held']) : 0;
    }

    public static function getAvailable(\mysqli $conn, int $userId): int
    {
        $stmt = $conn->prepare("SELECT GREATEST(0, meelcoin - meelcoin_held) AS available FROM users WHERE id = ?");
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ? max(0, (int)$row['available']) : 0;
    }

    /**
     * @return array{total:int,held:int,available:int}
     */
    public static function getBalanceSummary(\mysqli $conn, int $userId): array
    {
        $stmt = $conn->prepare(
            "SELECT meelcoin, meelcoin_held,
                    GREATEST(0, meelcoin - meelcoin_held) AS available
               FROM users WHERE id = ?"
        );
        if (!$stmt) {
            return ['total' => 0, 'held' => 0, 'available' => 0];
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return ['total' => 0, 'held' => 0, 'available' => 0];
        }
        return [
            'total' => max(0, (int)$row['meelcoin']),
            'held' => max(0, (int)$row['meelcoin_held']),
            'available' => max(0, (int)$row['available']),
        ];
    }

    public static function canAfford(\mysqli $conn, int $userId, int $amount): bool
    {
        if ($amount <= 0) {
            return true;
        }
        return self::getAvailable($conn, $userId) >= $amount;
    }

    public static function spend(\mysqli $conn, int $userId, int $amount, string $reason): array
    {
        if ($amount <= 0) {
            return [true, ''];
        }

        $summary = self::getBalanceSummary($conn, $userId);
        $balanceBefore = $summary['total'];
        $availableBefore = $summary['available'];
        $role = self::roleOf($conn, $userId);
        $wasFull = $role !== null && $balanceBefore >= self::getMax($conn, $role);

        $stmt = $conn->prepare(
            "UPDATE users
                SET meelcoin = meelcoin - ?
              WHERE id = ?
                AND meelcoin >= ?
                AND meelcoin - meelcoin_held >= ?"
        );
        if (!$stmt) {
            return [false, 'Gagal memperbarui saldo MEeLCoin.'];
        }
        $stmt->bind_param("iiii", $amount, $userId, $amount, $amount);
        if (!$stmt->execute()) {
            $stmt->close();
            return [false, 'Gagal memperbarui saldo MEeLCoin.'];
        }
        $affected = $stmt->affected_rows;
        $stmt->close();

        if ($affected < 1) {
            return [
                false,
                'MEeLCoin tidak cukup. Dibutuhkan: ' . $amount . ', tersedia: ' . $availableBefore,
            ];
        }

        if ($wasFull) {
            self::restartRefillWindow($conn, $userId);
        }

        self::log($conn, $userId, -$amount, self::getBalance($conn, $userId), $reason);
        return [true, ''];
    }
    public static function databaseInTransaction(\mysqli $conn): bool
    {
        $row = self::queryRow($conn, "SELECT @@in_transaction AS in_tx");
        if ($row !== null) {
            return (int)($row['in_tx'] ?? 0) === 1;
        }

        if (self::savepointProbeInTransaction($conn)) {
            return true;
        }

        $row = self::queryRow(
            $conn,
            "SELECT COUNT(*) AS in_tx FROM information_schema.INNODB_TRX
              WHERE trx_mysql_thread_id = CONNECTION_ID()"
        );
        return $row !== null && (int)($row['in_tx'] ?? 0) > 0;
    }
    /**
     * @return array<string,mixed>|null
     */
    private static function queryRow(\mysqli $conn, string $sql): ?array
    {
        try {
            $result = $conn->query($sql);
        } catch (Throwable $e) {
            return null;
        }
        if (!$result instanceof \mysqli_result) {
            return null;
        }
        $row = $result->fetch_assoc();
        $result->free();
        return is_array($row) ? $row : null;
    }
    private static function savepointProbeInTransaction(\mysqli $conn): bool
    {
        $name = 'meel_tx_probe_' . bin2hex(random_bytes(4));
        try {
            $conn->query("SAVEPOINT `{$name}`");
        } catch (Throwable $e) {
            return false;
        }
        try {
            $conn->query("RELEASE SAVEPOINT `{$name}`");
        } catch (Throwable $e) {
            return false;
        }
        return true;
    }
    private static function ownsTransaction(\mysqli $conn): bool
    {
        return !self::databaseInTransaction($conn);
    }
    public static function isEligibleUser(\mysqli $conn, int $userId): bool
    {
        $stmt = $conn->prepare("SELECT role, is_active FROM users WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$row) {
            return false;
        }
        if ((int)$row['is_active'] !== 1) {
            return false;
        }
        $role = strtolower((string)($row['role'] ?? ''));
        return $role !== '' && $role !== 'guest';
    }
    /**
     * @return array{ok:bool,error:string,hold_id:int,available:int,held:int}
     */
    public static function hold(\mysqli $conn,int $userId,int $amount,string $reason = 'upload',string $mediaType = ''
    ): array {
        $empty = ['ok' => false, 'error' => '', 'hold_id' => 0, 'available' => 0, 'held' => 0];
        if ($amount <= 0) {
            return ['ok' => true, 'error' => '', 'hold_id' => 0, 'available' => self::getAvailable($conn, $userId), 'held' => self::getHeld($conn, $userId)];
        }
        $owns = self::ownsTransaction($conn);
        if ($owns) {
            $conn->begin_transaction();
        }
        try {
            $stmt = $conn->prepare(
                "SELECT role, is_active, meelcoin, meelcoin_held
                   FROM users WHERE id = ? FOR UPDATE"
            );
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $userRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$userRow) {
                throw new RuntimeException('Akun tidak ditemukan.');
            }
            $role = strtolower((string)($userRow['role'] ?? ''));
            if ((int)$userRow['is_active'] !== 1) {
                throw new RuntimeException('Akun tidak aktif. Aktifkan akun sebelum upload.');
            }
            if ($role === '' || $role === 'guest') {
                throw new RuntimeException('Guest tidak dapat memakai MEeLCoin.');
            }

            $total = max(0, (int)$userRow['meelcoin']);
            $held = max(0, (int)$userRow['meelcoin_held']);
            $available = max(0, $total - $held);
            if ($available < $amount) {
                throw new RuntimeException('MEeLCoin tersedia tidak cukup. Dibutuhkan: ' . $amount . ', tersedia: ' . $available);
            }

            $stmt = $conn->prepare(
                "UPDATE users SET meelcoin_held = meelcoin_held + ?
                  WHERE id = ? AND meelcoin - meelcoin_held >= ?"
            );
            $stmt->bind_param("iii", $amount, $userId, $amount);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                $stmt->close();
                throw new RuntimeException('Gagal mereservasi MEeLCoin.');
            }
            $stmt->close();

            $ttl = self::HOLD_TTL_SECONDS;
            $reason = substr($reason !== '' ? $reason : 'upload', 0, 50);
            $mediaType = substr($mediaType, 0, 20);
            $stmt = $conn->prepare(
                "INSERT INTO coin_holds
                    (user_id, amount, status, reason, media_type, last_heartbeat, expires_at)
                 VALUES (?, ?, 'held', ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? SECOND))"
            );
            $stmt->bind_param("iissi", $userId, $amount, $reason, $mediaType, $ttl);
            $stmt->execute();
            $holdId = (int)$conn->insert_id;
            $stmt->close();

            if ($owns) {
                $conn->commit();
            }
            return [
                'ok' => true,
                'error' => '',
                'hold_id' => $holdId,
                'available' => $available - $amount,
                'held' => $held + $amount,
            ];
        } catch (Throwable $e) {
            if ($owns) {
                $conn->rollback();
            }
            $empty['error'] = $e->getMessage();
            $empty['available'] = self::getAvailable($conn, $userId);
            $empty['held'] = self::getHeld($conn, $userId);
            return $empty;
        }
    }
    /**
     * @return array<string,mixed>|null
     */
    public static function getHold(\mysqli $conn, int $holdId): ?array
    {
        $stmt = $conn->prepare("SELECT * FROM coin_holds WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $holdId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
    public static function touchHold(\mysqli $conn, int $holdId, ?int $attemptId = null): bool
    {
        if ($holdId <= 0) {
            return false;
        }
        $stmt = $conn->prepare(
            "UPDATE coin_holds SET last_heartbeat = NOW()
              WHERE id = ? AND status = 'held'"
        );
        $stmt->bind_param("i", $holdId);
        $stmt->execute();
        $ok = $stmt->affected_rows > 0;
        $stmt->close();
        if ($attemptId !== null && $attemptId > 0) {
            $stmt = $conn->prepare(
                "UPDATE upload_attempts SET last_heartbeat = NOW()
                  WHERE id = ? AND status = 'active'"
            );
            $stmt->bind_param("i", $attemptId);
            $stmt->execute();
            $stmt->close();
        }
        return $ok;
    }
    /**
     * @return array{ok:bool,error:string,balance:int,amount:int,status:string}
     */
    public static function captureHoldInTransaction(
        \mysqli $conn,
        int $holdId,
        int $userId,
        string $reason = 'upload',
        ?int $attemptId = null,
        ?string $mediaTable = null,
        ?int $mediaId = null
    ): array {
        $result = ['ok' => false, 'error' => '', 'balance' => 0, 'amount' => 0, 'status' => ''];
        if ($holdId <= 0) {
            return ['ok' => true, 'error' => '', 'balance' => self::getBalance($conn, $userId), 'amount' => 0, 'status' => 'none'];
        }
        try {
            $stmt = $conn->prepare(
                "SELECT id, user_id, amount, status
                   FROM coin_holds WHERE id = ? FOR UPDATE"
            );
            $stmt->bind_param("i", $holdId);
            $stmt->execute();
            $hold = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$hold) {
                throw new RuntimeException('Reservasi MEeLCoin tidak ditemukan.');
            }
            if ((int)$hold['user_id'] !== $userId) {
                throw new RuntimeException('Reservasi MEeLCoin bukan milik user ini.');
            }
            $status = (string)$hold['status'];
            $amount = max(0, (int)$hold['amount']);
            if ($status === 'captured') {
                return ['ok' => true, 'error' => '', 'balance' => self::getBalance($conn, $userId), 'amount' => 0, 'status' => 'captured'];
            }
            if ($status !== 'held') {
                throw new RuntimeException('Reservasi MEeLCoin sudah ' . $status . '.');
            }

            $stmt = $conn->prepare(
                "SELECT role, is_active, meelcoin, meelcoin_held
                   FROM users WHERE id = ? FOR UPDATE"
            );
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $userRow = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$userRow) {
                throw new RuntimeException('Akun tidak ditemukan.');
            }
            $role = strtolower((string)($userRow['role'] ?? ''));
            if ((int)$userRow['is_active'] !== 1 || $role === '' || $role === 'guest') {
                throw new RuntimeException('Akun tidak valid atau tidak aktif.');
            }
            if ($attemptId !== null && $attemptId > 0) {
                $stmt = $conn->prepare(
                    "SELECT id, user_id, hold_id, status, media_type, client_heartbeat_at,
                            TIMESTAMPDIFF(SECOND, client_heartbeat_at, NOW()) AS client_age
                       FROM upload_attempts
                      WHERE id = ? AND user_id = ? FOR UPDATE"
                );
                $stmt->bind_param("ii", $attemptId, $userId);
                $stmt->execute();
                $attempt = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$attempt || (int)$attempt['hold_id'] !== $holdId) {
                    throw new RuntimeException('Sesi upload tidak cocok dengan reservasi coin.');
                }
                if ((string)$attempt['status'] !== 'active') {
                    throw new RuntimeException('Sesi upload sudah ' . (string)$attempt['status'] . '.');
                }
                if (strtolower((string)$attempt['media_type']) !== 'advanced') {
                    $clientAge = $attempt['client_age'] === null ? null : (int)$attempt['client_age'];
                    if ($clientAge === null || $clientAge > self::CLIENT_HEARTBEAT_MAX_AGE) {
                        throw new RuntimeException('Heartbeat upload berhenti; upload dibatalkan tanpa memotong coin.');
                    }
                }
            }

            if ($mediaTable !== null) {
                if (!in_array($mediaTable, ['video', 'music'], true)) {
                    throw new RuntimeException('Tabel media tidak valid.');
                }
                if ($mediaId === null || $mediaId <= 0) {
                    throw new RuntimeException('Baris media belum tersedia.');
                }
                $stmt = $conn->prepare("SELECT id FROM `{$mediaTable}` WHERE id = ? AND user_id = ? LIMIT 1");
                $stmt->bind_param("ii", $mediaId, $userId);
                $stmt->execute();
                $mediaRow = $stmt->get_result()->fetch_assoc();
                $stmt->close();
                if (!$mediaRow) {
                    throw new RuntimeException('Baris media tidak ditemukan pada transaksi ini.');
                }
            }
            $total = (int)$userRow['meelcoin'];
            $held = (int)$userRow['meelcoin_held'];
            if ($total < $amount || $held < $amount) {
                throw new RuntimeException('Saldo MEeLCoin tidak konsisten dengan reservasi.');
            }
            $stmt = $conn->prepare(
                "UPDATE users
                    SET meelcoin = meelcoin - ?, meelcoin_held = meelcoin_held - ?
                  WHERE id = ? AND meelcoin >= ? AND meelcoin_held >= ?"
            );
            $stmt->bind_param("iiiii", $amount, $amount, $userId, $amount, $amount);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                $stmt->close();
                throw new RuntimeException('Gagal memotong MEeLCoin dari reservasi.');
            }
            $stmt->close();
            $stmt = $conn->prepare(
                "UPDATE coin_holds
                    SET status = 'captured', captured_at = NOW()
                  WHERE id = ? AND status = 'held'"
            );
            $stmt->bind_param("i", $holdId);
            $stmt->execute();
            if ($stmt->affected_rows < 1) {
                $stmt->close();
                throw new RuntimeException('Gagal menandai reservasi MEeLCoin sebagai captured.');
            }
            $stmt->close();
            $newBalance = $total - $amount;
            $reason = substr($reason !== '' ? $reason : 'upload', 0, 50);
            self::log($conn, $userId, -$amount, $newBalance, $reason, $holdId);

            if ($attemptId !== null && $attemptId > 0) {
                $stmt = $conn->prepare(
                    "UPDATE upload_attempts
                        SET hold_id = ?, stage = 'coin_captured'
                      WHERE id = ? AND user_id = ? AND status = 'active'"
                );
                $stmt->bind_param("iii", $holdId, $attemptId, $userId);
                $stmt->execute();
                $stmt->close();
            }
            return ['ok' => true, 'error' => '', 'balance' => $newBalance, 'amount' => $amount, 'status' => 'captured'];
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
            $result['balance'] = self::getBalance($conn, $userId);
            return $result;
        }
    }
    public static function captureHold(\mysqli $conn,int $holdId,int $userId,string $reason = 'upload',?int $attemptId = null,?string $mediaTable = null,?int $mediaId = null
    ): array {
        $owns = self::ownsTransaction($conn);
        if ($owns) {
            $conn->begin_transaction();
        }
        $result = self::captureHoldInTransaction($conn, $holdId, $userId, $reason, $attemptId, $mediaTable, $mediaId);
        if ($owns) {
            if ($result['ok']) {
                $conn->commit();
            } else {
                $conn->rollback();
            }
        }
        return $result;
    }
    /**
     * @return array{ok:bool,error:string,released:int,status:string}
     */
    public static function releaseHoldInTransaction(\mysqli $conn,int $holdId,string $reason = 'released',string $status = 'released'
    ): array {
        if (!in_array($status, ['released', 'expired'], true)) {
            $status = 'released';
        }
        if ($holdId <= 0) {
            return ['ok' => true, 'error' => '', 'released' => 0, 'status' => 'none'];
        }
        try {
            $stmt = $conn->prepare(
                "SELECT id, user_id, amount, status
                   FROM coin_holds WHERE id = ? FOR UPDATE"
            );
            $stmt->bind_param("i", $holdId);
            $stmt->execute();
            $hold = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            if (!$hold) {
                return ['ok' => true, 'error' => '', 'released' => 0, 'status' => 'missing'];
            }
            $current = (string)$hold['status'];
            if ($current === 'captured') {
                return ['ok' => true, 'error' => '', 'released' => 0, 'status' => 'captured'];
            }
            if ($current === 'released' || $current === 'expired') {
                return ['ok' => true, 'error' => '', 'released' => 0, 'status' => $current];
            }
            $userId = (int)$hold['user_id'];
            $amount = max(0, (int)$hold['amount']);
            if ($amount > 0) {
                $stmt = $conn->prepare(
                    "UPDATE users
                        SET meelcoin_held = GREATEST(0, meelcoin_held - ?)
                      WHERE id = ?"
                );
                $stmt->bind_param("ii", $amount, $userId);
                $stmt->execute();
                $stmt->close();
            }
            $reason = substr($reason !== '' ? $reason : 'released', 0, 50);
            $stmt = $conn->prepare(
                "UPDATE coin_holds
                    SET status = ?, released_at = NOW(), release_reason = ?
                  WHERE id = ? AND status = 'held'"
            );
            $stmt->bind_param("ssi", $status, $reason, $holdId);
            $stmt->execute();
            $affected = $stmt->affected_rows;
            $stmt->close();
            if ($affected < 1) {
                return ['ok' => false, 'error' => 'Gagal melepas reservasi MEeLCoin.', 'released' => 0, 'status' => $current];
            }
            return ['ok' => true, 'error' => '', 'released' => $amount, 'status' => $status];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'released' => 0, 'status' => ''];
        }
    }
    public static function releaseHold(\mysqli $conn,int $holdId,string $reason = 'released',string $status = 'released'
    ): array {
        if ($holdId <= 0) {
            return ['ok' => true, 'error' => '', 'released' => 0, 'status' => 'none'];
        }
        $owns = self::ownsTransaction($conn);
        if ($owns) {
            $conn->begin_transaction();
        }
        $result = self::releaseHoldInTransaction($conn, $holdId, $reason, $status);
        if ($owns) {
            if ($result['ok']) {
                $conn->commit();
            } else {
                $conn->rollback();
            }
        }
        return $result;
    }
    public static function releaseExpiredHolds(\mysqli $conn, int $limit = 100): int
    {
        $limit = max(1, min(1000, $limit));
        $stale = self::HOLD_STALE_SECONDS;
        $stmt = $conn->prepare(
            "SELECT id FROM coin_holds
              WHERE status = 'held'
                AND (expires_at < NOW() OR last_heartbeat < DATE_SUB(NOW(), INTERVAL ? SECOND))
              ORDER BY id ASC LIMIT {$limit}"
        );
        if (!$stmt) {
            return 0;
        }
        $stmt->bind_param("i", $stale);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $released = 0;
        foreach ($rows as $row) {
            $res = self::releaseHold($conn, (int)$row['id'], 'stale_hold', 'expired');
            if ($res['ok'] && $res['released'] > 0) {
                $released++;
            }
        }
        return $released;
    }
    private static function roleOf(\mysqli $conn, int $userId): ?string
    {
        if (!function_exists('get_user_role')) {
            return null;
        }
        $role = get_user_role($conn, $userId);
        return is_string($role) && $role !== '' ? $role : null;
    }
    private static function restartRefillWindow(\mysqli $conn, int $userId): void
    {
        $stmt = $conn->prepare("UPDATE users SET meelcoin_last_refill = NOW() WHERE id = ?");
        if (!$stmt) {
            return;
        }
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $stmt->close();
    }
    public static function refund(\mysqli $conn, int $userId, int $amount, string $reason): bool
    {
        if ($amount <= 0) return true;
        $stmt = $conn->prepare("UPDATE users SET meelcoin = meelcoin + ? WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param("ii", $amount, $userId);
        $ok = $stmt->execute() && $stmt->affected_rows > 0;
        $stmt->close();
        if ($ok) {
            self::log($conn, $userId, $amount, self::getBalance($conn, $userId), $reason);
        }
        return $ok;
    }
    public static function refill(\mysqli $conn, int $userId, string $role): bool
    {
        if ($role === 'admin') return true;
        $refillHours = self::getRefillHours($conn);
        $maxCoins = self::getMax($conn, $role);
        $refillAmt = self::getRefillAmount($conn, $role);
        if ($refillAmt <= 0) return false;
        $current = self::getBalance($conn, $userId);
        if ($current >= $maxCoins) {
            return false;
        }
        $stmt = $conn->prepare(
            "UPDATE users
                SET meelcoin = LEAST(?, meelcoin + ?),
                    meelcoin_last_refill = NOW()
              WHERE id = ?
                AND meelcoin = ?
                AND meelcoin < ?
                AND (meelcoin_last_refill IS NULL OR meelcoin_last_refill <= NOW() - INTERVAL ? HOUR)"
        );
        if (!$stmt) return false;
        $stmt->bind_param("iiiisi", $maxCoins, $refillAmt, $userId, $current, $maxCoins, $refillHours);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok || $affected < 1) return false;
        $added = min($refillAmt, $maxCoins - $current);
        if ($added <= 0) return false;
        self::log($conn, $userId, $added, $current + $added, 'refill');
        self::notifyRefill($conn, $userId, $added);
        return true;
    }
    private static function notifyRefill(\mysqli $conn, int $userId, int $added): void
    {
        if ($added <= 0 || !class_exists('Notification')) {
            return;
        }
        Notification::create(
            $conn,
            $userId,
            'meelcoin',
            'Isi Ulang MEeLCoin',
            'Saldo Anda bertambah ' . $added . ' dari isi ulang.'
        );
    }
    public static function getRefillCountdown(\mysqli $conn, int $userId, string $role): int
    {
        if ($role === 'admin') return 0;
        $cycleSeconds = max(1, self::getRefillHours($conn) * 3600);
        $stmt = $conn->prepare(
            "SELECT meelcoin_last_refill AS last_refill,
                    IFNULL(TIMESTAMPDIFF(SECOND, meelcoin_last_refill, NOW()), 0) AS elapsed
               FROM users WHERE id = ?"
        );
        if (!$stmt) return 0;
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $last = (string) ($row['last_refill'] ?? '');
        if ($last === '' || str_starts_with($last, '0000-00-00')) {
            return 0;
        }
        $elapsed = max(0, (int) ($row['elapsed'] ?? 0));
        return max(0, $cycleSeconds - $elapsed);
    }
    public static function initialize(\mysqli $conn, int $userId, string $role): void
    {
        if ($role === 'admin') return;
        $maxCoins = self::getMax($conn, $role);
        $stmt = $conn->prepare("SELECT id FROM coin_holds WHERE user_id = ? AND status = 'held'");
        if ($stmt) {
            $stmt->bind_param("i", $userId);
            $stmt->execute();
            $holds = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            foreach ($holds as $hold) {
                self::releaseHold($conn, (int)$hold['id'], 'account_initialized', 'released');
            }
        }
        $stmt = $conn->prepare("UPDATE users SET meelcoin = ?, meelcoin_held = 0, meelcoin_last_refill = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $maxCoins, $userId);
        $stmt->execute();
        $stmt->close();
        self::log($conn, $userId, $maxCoins, $maxCoins, 'init');
    }
    public static function log(\mysqli $conn,int $userId,int $amount,int $balanceAfter,string $reason,?int $holdId = null
    ): void {
        $stmt = $conn->prepare(
            "INSERT INTO meelcoin_log (user_id, amount, balance_after, reason, hold_id)
             VALUES (?, ?, ?, ?, NULLIF(?, 0))"
        );
        if (!$stmt) {
            error_log('[MEeL] MeelCoin::log prepare gagal: ' . $conn->error);
            return;
        }
        $hold = $holdId ?? 0;
        $stmt->bind_param("iiisi", $userId, $amount, $balanceAfter, $reason, $hold);
        if (!$stmt->execute()) {
            error_log('[MEeL] MeelCoin::log execute gagal: ' . $stmt->error);
        }
        $stmt->close();
    }
}
/* reference build: MEeL-C5H9NO2 [ffd332a] */