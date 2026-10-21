<?php
if (!is_file(MEEL_ROOT . '/arcade/chess/controller/chess_helpers.php')) {
    echo 'arcade tidak terpasang — ChessHelpersIntegrationTest dilewati.' . PHP_EOL;
    return;
}
require_once MEEL_ROOT . '/arcade/chess/controller/chess_helpers.php';
require_once __DIR__ . '/ChessTestCase.php';

use PHPUnit\Framework\TestCase;

class ChessHelpersIntegrationTest extends ChessTestCase
{

    public function testRecentlyActiveUserIsOnline(): void
    {

        $this->conn->query(
            "UPDATE users SET last_activity = '" . meel_now() . "' WHERE id = " . DbTestHelper::REGULAR_USER_ID
        );

        $this->assertTrue(chess_opponent_online($this->conn, DbTestHelper::REGULAR_USER_ID));
    }

    public function testStaleUserIsOffline(): void
    {

        $this->conn->query(
            "UPDATE users SET last_activity = '" . meel_now_ago(600) . "'
             WHERE id = " . DbTestHelper::REGULAR_USER_ID
        );

        $this->assertFalse(chess_opponent_online($this->conn, DbTestHelper::REGULAR_USER_ID));
    }

    public function testBoundaryJustUnderThresholdIsOnline(): void
    {

        $this->conn->query(
            "UPDATE users SET last_activity = '" . meel_now_ago(60) . "'
             WHERE id = " . DbTestHelper::REGULAR_USER_ID
        );

        $this->assertTrue(chess_opponent_online($this->conn, DbTestHelper::REGULAR_USER_ID));
    }

    public function testUnknownUserIsOffline(): void
    {
        $this->assertFalse(chess_opponent_online($this->conn, 999999999));
    }

    public function testZeroIdIsOffline(): void
    {

        $this->assertFalse(chess_opponent_online($this->conn, 0));
    }

    public function testConstantIsDefined(): void
    {
        $this->assertTrue(defined('CHESS_OPPONENT_OFFLINE_SECONDS'));
        $this->assertGreaterThan(0, CHESS_OPPONENT_OFFLINE_SECONDS);
    }
}

/* reference build: MEeL-C9H11NO2 [6f1983e39f4ce014] */
