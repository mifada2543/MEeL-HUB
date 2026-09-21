<?php

class MeelCoin
{
    private static ?array $settingsCache = null;

    private static function loadSettings(\mysqli $conn): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        $defaults = [
            'meelcoin_enabled'       => '1',
            'meelcoin_upload_cost'   => '5',
            'meelcoin_advanced_cost' => '10',
            'meelcoin_transcode_user_cost'   => '5',
            'meelcoin_transcode_member_cost' => '2',
            'meelcoin_user_max'      => '25',
            'meelcoin_user_refill'   => '15',
            'meelcoin_member_max'    => '50',
            'meelcoin_member_refill' => '25',
            'meelcoin_refill_hours'  => '5',
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

    public static function canAfford(\mysqli $conn, int $userId, int $amount): bool
    {
        return self::getBalance($conn, $userId) >= $amount;
    }

    public static function spend(\mysqli $conn, int $userId, int $amount, string $reason): array
    {
        if ($amount <= 0) {
            return [true, ''];
        }

        $stmt = $conn->prepare("UPDATE users SET meelcoin = meelcoin - ? WHERE id = ? AND meelcoin >= ?");
        if (!$stmt) {
            return [false, 'Gagal memperbarui saldo MEeLCoin.'];
        }
        $stmt->bind_param("iii", $amount, $userId, $amount);
        if (!$stmt->execute()) {
            $stmt->close();
            return [false, 'Gagal memperbarui saldo MEeLCoin.'];
        }
        $affected = $stmt->affected_rows;
        $stmt->close();

        // 0 baris terpengaruh = guard gagal (saldo kurang) atau user tidak ada.
        if ($affected < 1) {
            $balance = self::getBalance($conn, $userId);
            return [false, 'MEeLCoin tidak cukup. Dibutuhkan: ' . $amount . ', tersedia: ' . $balance];
        }

        self::log($conn, $userId, -$amount, self::getBalance($conn, $userId), $reason);
        return [true, ''];
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
        $maxCoins    = self::getMax($conn, $role);
        $refillAmt   = self::getRefillAmount($conn, $role);
        if ($refillAmt <= 0) return false;
        $current = self::getBalance($conn, $userId);
        if ($current >= $maxCoins) {
            $stmt = $conn->prepare("UPDATE users SET meelcoin_last_refill = NOW() WHERE id = ? AND meelcoin >= ?");
            if ($stmt) {
                $stmt->bind_param("ii", $userId, $maxCoins);
                $stmt->execute();
                $stmt->close();
            }
            return false;
        }

        $threshold = date('Y-m-d H:i:s', time() - ($refillHours * 3600));
        $stmt = $conn->prepare(
            "UPDATE users
                SET meelcoin = LEAST(?, meelcoin + ?),
                    meelcoin_last_refill = NOW()
              WHERE id = ?
                AND meelcoin = ?
                AND meelcoin < ?
                AND (meelcoin_last_refill IS NULL OR meelcoin_last_refill <= ?)"
        );
        if (!$stmt) return false;
        $stmt->bind_param("iiiiis", $maxCoins, $refillAmt, $userId, $current, $maxCoins, $threshold);
        $ok = $stmt->execute();
        $affected = $stmt->affected_rows;
        $stmt->close();
        if (!$ok || $affected < 1) return false;
        $added = min($refillAmt, $maxCoins - $current);
        if ($added <= 0) return false;
        self::log($conn, $userId, $added, $current + $added, 'refill');
        return true;
    }

    public static function getRefillCountdown(\mysqli $conn, int $userId, string $role): int
    {
        if ($role === 'admin') return 0;

        $cycleSeconds = max(1, self::getRefillHours($conn) * 3600);
        $stmt = $conn->prepare("SELECT meelcoin_last_refill FROM users WHERE id = ?");
        if (!$stmt) return 0;
        $stmt->bind_param("i", $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $last = $row['meelcoin_last_refill'] ?? null;
        if ($last === null || $last === '' || str_starts_with((string)$last, '0000-00-00')) {
            return 0;
        }

        $elapsed = max(0, time() - (int)strtotime((string)$last));
        return max(0, $cycleSeconds - $elapsed);
    }

    public static function initialize(\mysqli $conn, int $userId, string $role): void
    {
        if ($role === 'admin') return;

        $maxCoins = self::getMax($conn, $role);

        $stmt = $conn->prepare("UPDATE users SET meelcoin = ?, meelcoin_last_refill = NOW() WHERE id = ?");
        $stmt->bind_param("ii", $maxCoins, $userId);
        $stmt->execute();
        $stmt->close();

        self::log($conn, $userId, $maxCoins, $maxCoins, 'init');
    }

    public static function log(\mysqli $conn, int $userId, int $amount, int $balanceAfter, string $reason): void
    {
        $stmt = $conn->prepare("INSERT INTO meelcoin_log (user_id, amount, balance_after, reason) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("iiis", $userId, $amount, $balanceAfter, $reason);
        $stmt->execute();
        $stmt->close();
    }
}

/* reference build: MEeL-C5H9NO2 [95b3e98f03ddf48e] */
