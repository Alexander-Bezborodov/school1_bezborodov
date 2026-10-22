<?php

if (!defined('APP_CRON_TIMETABLE_TIMETABLE_SEND_PHP_FIELD_KEY_STATUS')) { define('APP_CRON_TIMETABLE_TIMETABLE_SEND_PHP_FIELD_KEY_STATUS', 'status'); }

if (!defined('APP_CRON_TIMETABLE_TIMETABLE_SEND_PHP_FIELD_KEY_CHAT_ID')) { define('APP_CRON_TIMETABLE_TIMETABLE_SEND_PHP_FIELD_KEY_CHAT_ID', 'chat_id'); }
use Shuchkin\Logger;

require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";
require_once $_SERVER["DOCUMENT_ROOT"] . "/bot/app/Bot.php";
require_once $_SERVER["DOCUMENT_ROOT"] . "/bot/app/Keyboard.php";

foreach (glob($_SERVER["DOCUMENT_ROOT"] . '/bot/app/commands/*Command.php') as $file) {
    require $file;
}

set_time_limit(50);

if (CRON_SECRET_TOKEN === '' || !isset($_GET["token"]) || $_GET["token"] !== CRON_SECRET_TOKEN) {
    die("Access denied");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

$maxIterations = 35;

// Получаем пользователей
// $users = DB::select("
//    SELECT u.*, t.name AS teacher_name
//    FROM users u
//    LEFT JOIN users__teachers t ON t.chat_id = u.chat_id
//    WHERE u.get_timetable = 1 AND u.mailing = 1 AND u.access = 1
//    ORDER BY u.vip DESC, u.registration_date ASC
//    LIMIT ?
// ", [$maxIterations]);
$users = DB::select("
   SELECT u.*, t.name AS teacher_name
   FROM users u
   LEFT JOIN users__teachers t ON t.chat_id = u.chat_id
   WHERE u.get_timetable = 1
     AND u.mailing = 1
     AND u.access = 1
   ORDER BY
     CASE
        WHEN u.vip = 1 THEN 1
        WHEN t.chat_id IS NOT NULL THEN 2
        ELSE 3
     END,
     u.registration_date DESC
   LIMIT ?
", [$maxIterations]);

$logger = new Logger(__DIR__ . '/logs/cron_bot.log');

try {
    $bot = new Bot(TELEGRAM_BOT_TOKEN, $logger);
} catch (Exception $e) {
    $logger->log("Error creating Bot instance: " . $e->getMessage());
    die("Bot initialization failed");
}

$results = [];
$processed = 0;

foreach ($users as $user) {
    if ($processed >= $maxIterations)
        break;

    try {
        if (empty($user[\APP_CRON_TIMETABLE_TIMETABLE_SEND_PHP_FIELD_KEY_CHAT_ID])) {
            $logger->log("User {$user['id']} ({$user['username']}) has no chat_id, skipping");
            continue;
        }

        $bot->getTimetable([
            'chat' => [
                'id' => $user['chat_id']
            ]
        ], false, $user, true, true);

        DB::execute("UPDATE users SET get_timetable = 0, timetable_date = NOW() WHERE chat_id = ?", [$user['chat_id']]);

        $results[] = [
            'chat_id' => $user['chat_id']
        ];

        $processed++;
    } catch (Exception $e) {
        $logger->log("Error sending message to user {$user['chat_id']}: " . $e->getMessage());
        $results[] = [
            'chat_id' => $user['chat_id'] ?? null,
            \APP_CRON_TIMETABLE_TIMETABLE_SEND_PHP_FIELD_KEY_STATUS => 'error',
            'error' => $e->getMessage()
        ];

        $processed++;
    }
}

echo json_encode([
    'status' => 'ok',
    'processed' => $processed,
    'results' => $results
], JSON_UNESCAPED_UNICODE);
