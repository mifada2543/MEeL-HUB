<?php
$host = 'localhost';
$user = 'root';
$pass = '';
$dbname = 'chess_game';

$conn = new mysqli($host, $user, $pass, $dbname);

if ($conn->connect_error) {
    http_response_code(500);
    die(json_encode([
        'success' => false,
        'message' => 'Database connection failed'
    ]));
}

$conn->set_charset('utf8mb4');
