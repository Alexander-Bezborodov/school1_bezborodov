<?php

if (!defined('APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_TOKEN')) { define('APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_TOKEN', 'token'); }

if (!defined('APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_LINE')) { define('APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_LINE', 'line'); }

if (!defined('APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_FILE')) { define('APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_FILE', 'file'); }

if (!defined('APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_MESSAGE')) { define('APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_MESSAGE', 'message'); }

if (!defined('APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_TYPE')) { define('APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_TYPE', 'type'); }
use Shuchkin\Logger;

set_time_limit(30);
register_shutdown_function("shutdown_notify");

require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";

$logger = new Logger(__DIR__ . '/bot.log');

//$logger->log($_SERVER["HTTP_X_REAL_IP"] ?? $_SERVER["REMOTE_ADDR"] ?? 'no ip');
//$logger->log(serialize($_GET));
//$logger->log(serialize($_POST));
//$logger->log(file_get_contents('php://input'));


//http_response_code(200);
//echo 'OK';
//flush();
//die();

//getWebhookInfo
// https://api.telegram.org/botYOUR_TELEGRAM_BOT_TOKEN/getWebhookInfo

function RegisterSiteError(int $type, string $message, string $file, int $line, string $text = "")
{
    global $isDebugVersion;
    if ($isDebugVersion) {
        return;
    }
    try {
        DB::insert(
            "INSERT INTO errors (creationtime, type, message, file, line, text)
             VALUES (NOW(), :type, :message, :file, :line, :text)",
            [
                \APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_TYPE => $type,
                \APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_MESSAGE => $message,
                \APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_FILE => $file,
                \APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_LINE => $line,
                'text' => $text
            ]
        );
    } catch (Exception $e) {
        error_log("Failed to register error: " . $e->getMessage());
    }
}

function shutdown_notify()
{
    // https://www.w3bai.com/ru/php/php_ref_error.html#gsc.tab=0
    $error = error_get_last();
    if (empty($error) || $error['type'] == 0 || $error['type'] == E_NOTICE) return;

    if ($error['type'] == E_ERROR && mb_strpos($error['message'], 'Maximum execution time') !== false) {
        die("<p>Формирование страницы затянулось на продолжительное время и было остановлено</p>");
    } elseif ($error['type'] == E_WARNING && mb_strpos($error['message'], 'mysqli_free_result()') !== false) {
        // не интересует
    } else {
        RegisterSiteError($error['type'], $error['message'], $error['file'], $error['line']);
    }
}

require __DIR__ . '/Bot.php';
require __DIR__ . '/Keyboard.php';
foreach (glob(__DIR__ . '/commands/*Command.php') as $file) {
    require $file;
}

// --- Проверка токена в URL ---
if (WEBHOOK_SECRET_TOKEN === '' || !isset($_GET[\APP_BOT_APP_WEBHOOK_PHP_FIELD_KEY_TOKEN]) || $_GET['token'] !== WEBHOOK_SECRET_TOKEN) {
    $logger->log("Forbidden access attempt from {$_SERVER['REMOTE_ADDR']} - invalid token");
    http_response_code(403);
    exit('{Forbidden}');
}

//--- Проверка IP Telegram ---
//$telegramIps = [
//    '149.154.160.0/20',
//    '91.108.4.0/22'
//];
//
//function ipInRange($ip, $range)
//{
//    list($subnet, $bits) = explode('/', $range);
//    $ip = ip2long($ip);
//    $subnet = ip2long($subnet);
//    $mask = -1 << (32 - $bits);
//    $subnet &= $mask;
//    return ($ip & $mask) === $subnet;
//}
//
$remoteIp = $_SERVER['REMOTE_ADDR'];
//$valid = false;
//foreach ($telegramIps as $range) {
//    if (ipInRange($remoteIp, $range)) {
//        $valid = true;
//        break;
//    }
//}
//if (!$valid) {
//    $logger->log("Forbidden access attempt from $remoteIp - not Telegram IP");
//    http_response_code(403);
//    exit('Forbidden');
//}

// --- Получаем тело запроса ---
$input = file_get_contents('php://input');
if (strlen($input) > 1000000) { // >1 МБ
    $logger->log("Request too large from $remoteIp");
    http_response_code(413);
    exit('Request too large');
}

$data = json_decode($input, true);
//if (!$data) {
//    $logger->log("Invalid JSON from $remoteIp: $input");
//    http_response_code(400);
//    exit('Invalid JSON');
//}

http_response_code(200);
echo 'OK';
flush();

try {
    $bot = new Bot(TELEGRAM_BOT_TOKEN, $logger);
    $bot->handleUpdate($data);
} catch (Exception $e) {
    $logger->log("Error handling update: " . $e->getMessage());
}

// Webhook and relay endpoints are configured through environment variables.
