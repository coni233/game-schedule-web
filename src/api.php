<?php
header("Content-Type: application/json; charset=utf-8");

require_once __DIR__ . "/db.php";

$config = require __DIR__ . "/config.php";
define("EDIT_PASSWORD", (string)($config["edit_password"] ?? ""));
define("ADMIN_PASSWORD", (string)($config["admin_password"] ?? ""));

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

function requirePostMethod() {
    if ($_SERVER["REQUEST_METHOD"] !== "POST") {
        jsonResponse([
            "success" => false,
            "message" => "请求方法错误"
        ], 405);
    }
}

function requireEditPassword($data) {
    $password = trim((string)($data["editPassword"] ?? ""));

    if ($password === "") {
        jsonResponse([
            "success" => false,
            "message" => "请输入编辑密码或管理员密码"
        ], 401);
    }

    $isEditPasswordValid = EDIT_PASSWORD !== "" && hash_equals(EDIT_PASSWORD, $password);
    $isAdminPasswordValid = ADMIN_PASSWORD !== "" && hash_equals(ADMIN_PASSWORD, $password);

    if (!$isEditPasswordValid && !$isAdminPasswordValid) {
        jsonResponse([
            "success" => false,
            "message" => "编辑密码或管理员密码错误"
        ], 403);
    }
}

function requireAdminPassword($data) {
    $password = trim((string)($data["adminPassword"] ?? ""));

    if ($password === "") {
        jsonResponse([
            "success" => false,
            "message" => "请输入管理员密码"
        ], 401);
    }

    if (ADMIN_PASSWORD === "" || !hash_equals(ADMIN_PASSWORD, $password)) {
        jsonResponse([
            "success" => false,
            "message" => "管理员密码错误"
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

function validateDateString($value, $fieldName = "日期") {
    $value = trim((string)$value);
    $date = DateTime::createFromFormat("!Y-m-d", $value);

    if (!$date || $date->format("Y-m-d") !== $value) {
        jsonResponse([
            "success" => false,
            "message" => $fieldName . "格式错误"
        ], 400);
    }

    return $value;
}

function getWeekStart($value) {
    $dateString = validateDateString($value, "周起始日期");
    $date = new DateTime($dateString);

    if (intval($date->format("N")) !== 1) {
        jsonResponse([
            "success" => false,
            "message" => "周起始日期必须是周一"
        ], 400);
    }

    return $dateString;
}

function getDayIndexFromDate($dateString) {
    $date = new DateTime($dateString);
    return intval($date->format("N")) - 1;
}

function getSlotId($dateString, $hour) {
    return $dateString . "_hour" . str_pad((string)$hour, 2, "0", STR_PAD_LEFT);
}

function getUnavailableInfo(PDO $pdo, $dateString, $hour) {
    $dayIndex = getDayIndexFromDate($dateString);
    $blocked = false;
    $reason = "";
    $source = null;

    $weeklyStmt = $pdo->prepare("
        SELECT id, reason
        FROM game_schedule_weekly_blocks
        WHERE day_index = ?
          AND start_hour <= ?
          AND end_hour > ?
        ORDER BY id ASC
    ");
    $weeklyStmt->execute([$dayIndex, $hour, $hour]);

    foreach ($weeklyStmt->fetchAll() as $row) {
        $blocked = true;
        $reason = $row["reason"];
        $source = "weekly";
    }

    $overrideStmt = $pdo->prepare("
        SELECT id, override_type, reason
        FROM game_schedule_date_overrides
        WHERE override_date = ?
          AND start_hour <= ?
          AND end_hour > ?
        ORDER BY id ASC
    ");
    $overrideStmt->execute([$dateString, $hour, $hour]);

    foreach ($overrideStmt->fetchAll() as $row) {
        if ($row["override_type"] === "allow") {
            $blocked = false;
            $reason = $row["reason"];
            $source = "date_allow";
        } else {
            $blocked = true;
            $reason = $row["reason"];
            $source = "date_block";
        }
    }

    return [
        "blocked" => $blocked,
        "reason" => $reason,
        "source" => $source
    ];
}

function getAvailabilityForWeek(PDO $pdo, $weekStart) {
    $weekEnd = (new DateTime($weekStart))->modify("+6 days")->format("Y-m-d");

    $weeklyStmt = $pdo->query("
        SELECT id, day_index, start_hour, end_hour, reason
        FROM game_schedule_weekly_blocks
        ORDER BY day_index ASC, start_hour ASC, id ASC
    ");

    $overrideStmt = $pdo->prepare("
        SELECT id, override_date, start_hour, end_hour, override_type, reason
        FROM game_schedule_date_overrides
        WHERE override_date BETWEEN ? AND ?
        ORDER BY override_date ASC, start_hour ASC, id ASC
    ");
    $overrideStmt->execute([$weekStart, $weekEnd]);

    return [
        "weeklyBlocks" => $weeklyStmt->fetchAll(),
        "dateOverrides" => $overrideStmt->fetchAll()
    ];
}

function validateTimeRange($data) {
    $startHour = getIntField($data, "startHour", 0, 23, "开始时间错误");
    $endHour = getIntField($data, "endHour", 1, 24, "结束时间错误");

    if ($endHour <= $startHour) {
        jsonResponse([
            "success" => false,
            "message" => "结束时间必须晚于开始时间"
        ], 400);
    }

    return [$startHour, $endHour];
}

function validateReason($data) {
    $reason = trim((string)($data["reason"] ?? ""));

    if ($reason === "") {
        jsonResponse([
            "success" => false,
            "message" => "请输入原因"
        ], 400);
    }

    if (textLength($reason) > 50) {
        jsonResponse([
            "success" => false,
            "message" => "原因不能超过 50 个字符"
        ], 400);
    }

    return $reason;
}

$action = $_GET["action"] ?? "";

if ($action === "list") {
    $weekStart = getWeekStart($_GET["weekStart"] ?? "");
    $weekEnd = (new DateTime($weekStart))->modify("+6 days")->format("Y-m-d");

    $stmt = $pdo->prepare("
        SELECT
            slot_id,
            schedule_date,
            day_name,
            day_index,
            hour,
            game,
            nickname,
            updated_at
        FROM game_schedule_slots
        WHERE schedule_date BETWEEN ? AND ?
        ORDER BY schedule_date ASC, hour ASC
    ");
    $stmt->execute([$weekStart, $weekEnd]);
    $items = $stmt->fetchAll();

    $participantsMap = [];

    if (count($items) > 0) {
        $slotIds = array_column($items, "slot_id");
        $placeholders = implode(",", array_fill(0, count($slotIds), "?"));

        $participantStmt = $pdo->prepare("
            SELECT slot_id, nickname
            FROM game_schedule_participants
            WHERE slot_id IN ($placeholders)
            ORDER BY created_at ASC, id ASC
        ");
        $participantStmt->execute($slotIds);

        foreach ($participantStmt->fetchAll() as $participant) {
            $slotId = $participant["slot_id"];

            if (!isset($participantsMap[$slotId])) {
                $participantsMap[$slotId] = [];
            }

            $participantsMap[$slotId][] = $participant["nickname"];
        }
    }

    foreach ($items as &$item) {
        $item["participants"] = $participantsMap[$item["slot_id"]] ?? [];
    }
    unset($item);

    $availability = getAvailabilityForWeek($pdo, $weekStart);

    jsonResponse([
        "success" => true,
        "weekStart" => $weekStart,
        "weekEnd" => $weekEnd,
        "items" => $items,
        "weeklyBlocks" => $availability["weeklyBlocks"],
        "dateOverrides" => $availability["dateOverrides"]
    ]);
}

if ($action === "verifyAdmin") {
    requirePostMethod();
    $data = getJsonInput();
    requireAdminPassword($data);

    jsonResponse([
        "success" => true,
        "message" => "管理员验证成功"
    ]);
}

if ($action === "save") {
    requirePostMethod();
    $data = getJsonInput();
    requireEditPassword($data);

    $scheduleDate = validateDateString($data["date"] ?? "", "日程日期");
    $hour = getIntField($data, "hour", 0, 23, "时间参数错误");
    $game = trim((string)($data["game"] ?? ""));
    $slotId = getSlotId($scheduleDate, $hour);

    // 留空表示删除。删除已有日程不受当前不可编辑规则限制。
    if ($game === "") {
        $stmt = $pdo->prepare("DELETE FROM game_schedule_slots WHERE slot_id = ?");
        $stmt->execute([$slotId]);

        jsonResponse([
            "success" => true,
            "message" => "已删除日程"
        ]);
    }

    $unavailable = getUnavailableInfo($pdo, $scheduleDate, $hour);

    if ($unavailable["blocked"]) {
        jsonResponse([
            "success" => false,
            "message" => "该时间段不可编辑：" . ($unavailable["reason"] ?: "不可编辑")
        ], 403);
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

    $dayIndex = getDayIndexFromDate($scheduleDate);
    global $days;
    $dayName = $days[$dayIndex];

    $stmt = $pdo->prepare("
        INSERT INTO game_schedule_slots
            (slot_id, schedule_date, day_name, day_index, hour, game, nickname)
        VALUES
            (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            schedule_date = VALUES(schedule_date),
            day_name = VALUES(day_name),
            day_index = VALUES(day_index),
            hour = VALUES(hour),
            game = VALUES(game),
            nickname = VALUES(nickname),
            updated_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        $slotId,
        $scheduleDate,
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

if ($action === "join") {
    requirePostMethod();
    $data = getJsonInput();
    requireEditPassword($data);

    $scheduleDate = validateDateString($data["date"] ?? "", "日程日期");
    $hour = getIntField($data, "hour", 0, 23, "时间参数错误");
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

    $slotId = getSlotId($scheduleDate, $hour);
    $checkStmt = $pdo->prepare("
        SELECT COUNT(*) AS count
        FROM game_schedule_slots
        WHERE slot_id = ?
    ");
    $checkStmt->execute([$slotId]);
    $row = $checkStmt->fetch();

    if (!$row || intval($row["count"]) === 0) {
        jsonResponse([
            "success" => false,
            "message" => "该时间段还没有日程，不能加入"
        ], 404);
    }

    $joining = filter_var($data["joining"] ?? false, FILTER_VALIDATE_BOOLEAN);

    if ($joining) {
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO game_schedule_participants
                (slot_id, nickname)
            VALUES
                (?, ?)
        ");
        $stmt->execute([$slotId, $nickname]);

        jsonResponse([
            "success" => true,
            "message" => "已加入该日程"
        ]);
    }

    $stmt = $pdo->prepare("
        DELETE FROM game_schedule_participants
        WHERE slot_id = ? AND nickname = ?
    ");
    $stmt->execute([$slotId, $nickname]);

    jsonResponse([
        "success" => true,
        "message" => "已取消加入该日程"
    ]);
}

if ($action === "clear") {
    requirePostMethod();
    $data = getJsonInput();
    requireAdminPassword($data);

    $weekStart = getWeekStart($data["weekStart"] ?? "");
    $weekEnd = (new DateTime($weekStart))->modify("+6 days")->format("Y-m-d");

    $stmt = $pdo->prepare("
        SELECT slot_id, schedule_date, hour
        FROM game_schedule_slots
        WHERE schedule_date BETWEEN ? AND ?
    ");
    $stmt->execute([$weekStart, $weekEnd]);
    $items = $stmt->fetchAll();

    $deleteIds = [];

    foreach ($items as $item) {
        $unavailable = getUnavailableInfo(
            $pdo,
            $item["schedule_date"],
            intval($item["hour"])
        );

        if (!$unavailable["blocked"]) {
            $deleteIds[] = $item["slot_id"];
        }
    }

    if (count($deleteIds) > 0) {
        $placeholders = implode(",", array_fill(0, count($deleteIds), "?"));
        $deleteStmt = $pdo->prepare("DELETE FROM game_schedule_slots WHERE slot_id IN ($placeholders)");
        $deleteStmt->execute($deleteIds);
    }

    jsonResponse([
        "success" => true,
        "deletedCount" => count($deleteIds),
        "message" => "已清空当前周全部可编辑日程"
    ]);
}

if ($action === "listUnavailableAdmin") {
    requirePostMethod();
    $data = getJsonInput();
    requireAdminPassword($data);

    $weeklyStmt = $pdo->query("
        SELECT id, day_index, start_hour, end_hour, reason, created_at, updated_at
        FROM game_schedule_weekly_blocks
        ORDER BY day_index ASC, start_hour ASC, id ASC
    ");

    $overrideStmt = $pdo->query("
        SELECT id, override_date, start_hour, end_hour, override_type, reason, created_at, updated_at
        FROM game_schedule_date_overrides
        ORDER BY override_date DESC, start_hour ASC, id ASC
    ");

    jsonResponse([
        "success" => true,
        "weeklyBlocks" => $weeklyStmt->fetchAll(),
        "dateOverrides" => $overrideStmt->fetchAll()
    ]);
}

if ($action === "addWeeklyBlock") {
    requirePostMethod();
    $data = getJsonInput();
    requireAdminPassword($data);

    $dayIndex = getIntField($data, "dayIndex", 0, 6, "星期参数错误");
    list($startHour, $endHour) = validateTimeRange($data);
    $reason = validateReason($data);

    $stmt = $pdo->prepare("
        INSERT INTO game_schedule_weekly_blocks
            (day_index, start_hour, end_hour, reason)
        VALUES
            (?, ?, ?, ?)
    ");
    $stmt->execute([$dayIndex, $startHour, $endHour, $reason]);

    jsonResponse([
        "success" => true,
        "message" => "固定每周规则已添加"
    ]);
}

if ($action === "deleteWeeklyBlock") {
    requirePostMethod();
    $data = getJsonInput();
    requireAdminPassword($data);

    $id = getIntField($data, "id", 1, PHP_INT_MAX, "规则 ID 错误");
    $stmt = $pdo->prepare("DELETE FROM game_schedule_weekly_blocks WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse([
        "success" => true,
        "message" => "固定每周规则已删除"
    ]);
}

if ($action === "addDateOverride") {
    requirePostMethod();
    $data = getJsonInput();
    requireAdminPassword($data);

    $overrideDate = validateDateString($data["overrideDate"] ?? "", "调整日期");
    list($startHour, $endHour) = validateTimeRange($data);
    $reason = validateReason($data);
    $overrideType = (string)($data["overrideType"] ?? "");

    if (!in_array($overrideType, ["block", "allow"], true)) {
        jsonResponse([
            "success" => false,
            "message" => "调整类型错误"
        ], 400);
    }

    $stmt = $pdo->prepare("
        INSERT INTO game_schedule_date_overrides
            (override_date, start_hour, end_hour, override_type, reason)
        VALUES
            (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$overrideDate, $startHour, $endHour, $overrideType, $reason]);

    jsonResponse([
        "success" => true,
        "message" => "指定日期调整已添加"
    ]);
}

if ($action === "deleteDateOverride") {
    requirePostMethod();
    $data = getJsonInput();
    requireAdminPassword($data);

    $id = getIntField($data, "id", 1, PHP_INT_MAX, "调整 ID 错误");
    $stmt = $pdo->prepare("DELETE FROM game_schedule_date_overrides WHERE id = ?");
    $stmt->execute([$id]);

    jsonResponse([
        "success" => true,
        "message" => "指定日期调整已删除"
    ]);
}

jsonResponse([
    "success" => false,
    "message" => "未知操作"
], 404);
