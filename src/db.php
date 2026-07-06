<?php
header("Content-Type: application/json; charset=utf-8");

$config = require __DIR__ . "/config.php";

$host = $config["db_host"];
$dbname = $config["db_name"];
$username = $config["db_user"];
$password = $config["db_password"];

try {
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "数据库连接失败"
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
