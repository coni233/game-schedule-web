<?php
header("Content-Type: application/json; charset=utf-8");

require_once "db.php";

$config = require __DIR__ . "/config.php";
define("EDIT_PASSWORD", $config["edit_password"]);

$days = ["周一", "周二", "周三", "周四", "周五", "周六", "周日"];

function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getJsonInput() {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);

    if (!is_array($data)) {
        jsonResponse([
            "success" => false,
            "message" => "请求数据格式错误"
        ], 400);
    }

    return $data;
}

function requireEditPassword($data) {
    $password = trim((string)($data["editPassword"] ?? ""));

    if ($password === "") {
        jsonResponse([
            "success" => false,
            "message" => "请输入编辑密码"
        ], 401);
    }

    if (!hash_equals(EDIT_PASSWORD, $password)) {
        jsonResponse([
            "success" => false,
            "message" => "编辑密码错误"
        ], 403);
    }
}

function getIntField($data, $field, $min, $max, $message) {
    if (!array_key_exists($field, $data)) {
        jsonResponse([
            "success" => false,
            "message" => $message
        ], 400);
    }

    $value = filter_var($data[$field], FILTER_VALIDATE_INT);

    if ($value === false || $value < $min || $value > $max) {
        jsonResponse([
            "success" => false,
            "message" => $message
        ], 400);
    }

    return intval($value);
}

function textLength($text) {
    if (function_exists("mb_strlen")) {
        return mb_strlen($text, "UTF-8");
    }

    return strlen($text);
}

function isWorkTime($dayIndex, $hour) {
    $isWeekday = $dayIndex >= 0 && $dayIndex <= 4;

    $isMorningWork = $hour >= 9 && $hour < 12;
    $isAfternoonWork = $hour >= 14 && $hour < 18;

    return $isWeekday && ($isMorningWork || $isAfternoonWork);
}

$action = $_GET["action"] ?? "";

if ($action === "list") {
    $stmt = $pdo->query("
        SELECT 
            slot_id, 
            day_name, 
            day_index, 
            hour, 
            game, 
            nickname,
            updated_at
        FROM game_schedule_slots
        ORDER BY day_index ASC, hour ASC
    ");

    jsonResponse([
        "success" => true,
        "items" => $stmt->fetchAll()
    ]);
}

if ($action === "save") {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        jsonResponse([
            "success" => false,
            "message" => "请求方法错误"
        ], 405);
    }

    $data = getJsonInput();
    requireEditPassword($data);

    $dayIndex = getIntField($data, "dayIndex", 0, 6, "星期参数错误");
    $hour = getIntField($data, "hour", 0, 23, "时间参数错误");

    if (isWorkTime($dayIndex, $hour)) {
        jsonResponse([
            "success" => false,
            "message" => "周一到周五 09:00-12:00、14:00-18:00 是上班时间，不能添加日程哦。"
        ], 403);
    }

    $slotId = "day{$dayIndex}_hour{$hour}";
    global $days;
    $dayName = $days[$dayIndex];

    $game = trim((string)($data["game"] ?? ""));

    // 游戏名留空，表示删除这个时间段的日程
    if ($game === "") {
        $stmt = $pdo->prepare("DELETE FROM game_schedule_slots WHERE slot_id = ?");
        $stmt->execute([$slotId]);

        jsonResponse([
            "success" => true,
            "message" => "已删除日程"
        ]);
    }

    if (textLength($game) > 50) {
        jsonResponse([
            "success" => false,
            "message" => "游戏名称不能超过 50 个字符"
        ], 400);
    }

    $nickname = trim((string)($data["nickname"] ?? ""));

    if ($nickname === "") {
        jsonResponse([
            "success" => false,
            "message" => "请输入昵称"
        ], 400);
    }

    if (textLength($nickname) > 20) {
        jsonResponse([
            "success" => false,
            "message" => "昵称不能超过 20 个字符"
        ], 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO game_schedule_slots 
            (slot_id, day_name, day_index, hour, game, nickname)
        VALUES 
            (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            day_name = VALUES(day_name),
            day_index = VALUES(day_index),
            hour = VALUES(hour),
            game = VALUES(game),
            nickname = VALUES(nickname),
            updated_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        $slotId,
        $dayName,
        $dayIndex,
        $hour,
        $game,
        $nickname
    ]);

    jsonResponse([
        "success" => true,
        "message" => "保存成功"
    ]);
}

if ($action === "clear") {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        jsonResponse([
            "success" => false,
            "message" => "请求方法错误"
        ], 405);
    }

    $data = getJsonInput();
    requireEditPassword($data);

    $stmt = $pdo->prepare("
        DELETE FROM game_schedule_slots
        WHERE NOT (
            day_index BETWEEN 0 AND 4
            AND (
                (hour >= 9 AND hour < 12)
                OR
                (hour >= 14 AND hour < 18)
            )
        )
    ");

    $stmt->execute();

    jsonResponse([
        "success" => true,
        "message" => "已清空全部可编辑日程"
    ]);
}

jsonResponse([
    "success" => false,
    "message" => "未知操作"
], 404);
