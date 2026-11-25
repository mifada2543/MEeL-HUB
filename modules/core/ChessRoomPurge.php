<?php
if (!function_exists('purge_inactive_chess_rooms')) {
    function purge_inactive_chess_rooms(mysqli $conn, int $idleMinutes = 10): array
    {
        static $tableReady = null;
        if ($tableReady === null) {
            $check = @$conn->query("SHOW TABLES LIKE 'rooms'");
            $tableReady = ($check && $check->num_rows > 0);
        }
        if (!$tableReady) {
            return ['rooms' => 0, 'moves' => 0];
        }

        $minutes = max(1, $idleMinutes);
        $stmt = $conn->prepare(
            "SELECT r.room_code
             FROM rooms r
             LEFT JOIN (
                 SELECT room_code, MAX(created_at) AS last_activity
                 FROM moves GROUP BY room_code
             ) m ON m.room_code = r.room_code
             WHERE COALESCE(m.last_activity, r.created_at) < DATE_SUB(NOW(), INTERVAL ? MINUTE)"
        );
        if (!$stmt) {
            return ['rooms' => 0, 'moves' => 0];
        }
        $stmt->bind_param('i', $minutes);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['rooms' => 0, 'moves' => 0];
        }
        $res = $stmt->get_result();
        $codes = [];
        while ($row = $res->fetch_assoc()) {
            $codes[] = $row['room_code'];
        }
        $stmt->close();
        if ($codes === []) {
            return ['rooms' => 0, 'moves' => 0];
        }

        $placeholders = implode(',', array_fill(0, count($codes), '?'));
        $types = str_repeat('s', count($codes));
        $dm = $conn->prepare("DELETE FROM moves WHERE room_code IN ($placeholders)");
        if (!$dm) {
            return ['rooms' => 0, 'moves' => 0];
        }
        $dm->bind_param($types, ...$codes);
        $dm->execute();
        $movesDeleted = $dm->affected_rows;
        $dm->close();

        $dr = $conn->prepare("DELETE FROM rooms WHERE room_code IN ($placeholders)");
        if (!$dr) {
            return ['rooms' => 0, 'moves' => $movesDeleted];
        }
        $dr->bind_param($types, ...$codes);
        $dr->execute();
        $roomsDeleted = $dr->affected_rows;
        $dr->close();

        return ['rooms' => $roomsDeleted, 'moves' => $movesDeleted];
    }
}
