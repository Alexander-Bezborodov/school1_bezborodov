<?php
declare(strict_types=1);
if (!defined('APP_DASHBOARD_APP_INDEX_PHP_FIELD_KEY_PATH')) { define('APP_DASHBOARD_APP_INDEX_PHP_FIELD_KEY_PATH', 'path'); }


$allowedIp = getenv('DEBUG_IP') ?: '';

$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
// Отключаем вывод ошибок
if ($allowedIp === '' || $clientIp !== $allowedIp) {
    ini_set('display_errors', '0');
} else {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
}

// Корень приложения
$baseDir = realpath($_SERVER["DOCUMENT_ROOT"] . '/');
if ($baseDir === false) {
    error_log('Ошибка: не удалось определить базовую директорию приложения.');
    http_response_code(500);
    exit('Internal Server Error');
}

$requiredFiles = [
    $baseDir . '/functions/core.php',
    $baseDir . '/app/Router.php'
];
foreach ($requiredFiles as $file) {
    if (!is_readable($file)) {
        error_log("Missing required file: {$file}");
        http_response_code(500);
        exit('Internal Server Error');
    }
    require_once $file;
}

// ----------------------
// HTTP security headers
// ----------------------
header("X-Frame-Options: DENY");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: no-referrer-when-downgrade");
header("Permissions-Policy: geolocation=(), microphone=(), camera=()");
// CSP: разрешаем только ресурсы с текущего хоста по умолчанию; расширять осторожно
//header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none';");
// Отключаем автоматический перевод содержимого
header('X-Content-Translate: no');

// HTTP Strict Transport Security (включать только если сайт работает по HTTPS)
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    header('Strict-Transport-Security: max-age=63072000; includeSubDomains; preload');
}

// ----------------------
// Блокировка подозрительных User-Agent
// ----------------------
$badAgents = [
    'W3C_Validator',
    'Validator.nu',
    'Cynthia',
    'Google-Structured-Data-Testing-Tool',
    'Pingdom',
    'GTmetrix'
];
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
// Используем stripos — но также логируем попытки
foreach ($badAgents as $bad) {
    if ($bad !== '' && stripos($userAgent, $bad) !== false) {
        error_log('Blocked UA: ' . $userAgent . ' matched ' . $bad);
        http_response_code(403);
        exit('Access denied.');
    }
}

// ----------------------
// Сессии — безопасные параметры
// ----------------------
if (session_status() === PHP_SESSION_NONE) {
    // Надёжные параметры cookie для сессии
    $cookieParams = session_get_cookie_params();
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        \APP_DASHBOARD_APP_INDEX_PHP_FIELD_KEY_PATH => $cookieParams['path'] ?? '/',
        'domain' => $cookieParams['domain'] ?? '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax', // или 'Strict' при жёсткой политике
    ]);
    session_start();
}

// ----------------------
// Вспомогательные функции
// ----------------------
/**
 * Возвращает метку времени последней модификации файла для версионирования ресурсов.
 * Возвращает пустую строку, если файл отсутствует.
 */
function v(string $path): string
{
    $full = realpath($path);
    if ($full === false || !is_file($full)) {
        return '';
    }
    return (string) filemtime($full);
}

// ----------------------
// Маршрутизация и метаданные
// ----------------------
// Надёжная нормализация URI — избавляемся от опасных символов
$rawUri = $_SERVER['REQUEST_URI'] ?? '/';
$rawUri = str_replace("dashboard/", "", $rawUri);
// Оставляем только путь и query, удаляя потенциально опасные бинарные символы
$uri = preg_replace('/[^\x20-\x7E]/', '', $rawUri);
// Ограничиваем длину строки
$uri = mb_substr($uri, 0, 2048);

try {
    Router::setMetaFromUri($uri);
} catch (Throwable $ex) {
    error_log('Router::setMetaFromUri failed: ' . $ex->getMessage());
    http_response_code(500);
    exit('Internal Server Error');
}

// ----------------------
// Окончательная генерация HTML
// ----------------------
// Заголовок страницы и мета — безопасно выводим
$pageTitle = 'bezborodov - Расписание Первой школы';
$pageDescription = '';

// список всех скриптов
$libScripts = [
    "https://unpkg.com/@popperjs/core@2",
    "https://unpkg.com/tippy.js@6",
    PROJECT_DIR . "/js/lib/jquery_3_6_0.min.js",
    PROJECT_DIR . "/js/lib/flipdown_vi_mask.js",
    "https://cdn.jsdelivr.net/npm/flatpickr",
    "https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/ru.js",
    PROJECT_DIR . "/js/lib/gsap.min.js",
    PROJECT_DIR . "/js/lib/vanilla-tilt.min.js",
    PROJECT_DIR . "/js/lib/Window.js",
    PROJECT_DIR . "/js/lib/dataTables.min.js",
    "https://cdn.datatables.net/columncontrol/1.0.7/js/columnControl.dataTables.js",
    "https://cdn.datatables.net/colreorder/2.0.0/js/dataTables.colReorder.min.js",
    "https://cdn.jsdelivr.net/npm/peity@3.3.0/jquery.peity.min.js",
    "https://unpkg.com/filepond/dist/filepond.min.js",
    PROJECT_DIR . "/js/app.js",
    PROJECT_DIR . "/js/main.js"
]; ?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="<?= esc($pageDescription) ?>">
    <meta name="robots" content="noindex, nofollow">
    <title><?= esc($pageTitle) ?></title>
    <link rel="icon" href="/smslovers.ico" type="image/x-icon">
    <link rel="stylesheet" href="https://cdn.datatables.net/colreorder/2.0.0/css/colReorder.dataTables.min.css">
    <link rel="stylesheet" href="<?= PROJECT_DIR ?>/css/styles.min.css?v=<?= v('/css/styles.min.css') ?>">
    <link rel="stylesheet" href="https://unpkg.com/filepond/dist/filepond.css">
    <?php foreach ($libScripts as $src): ?>
        <script src="<?= esc($src) ?>?v=<?= v($src) ?>"></script>
    <?php endforeach; ?>
</head>

<body>
    <?php
    try {
        if ($clientIp !== $allowedIp && IS_MAINTENANCE_MODE) {
            require $baseDir . '/app/views/maintenance.php';
        } elseif (Auth::check()) {
            Router::renderViewFromUri($uri);
        } else {
            require $baseDir . '/app/views/auth.php';
        }
    } catch (Throwable $ex) {
        http_response_code(500);
        require $baseDir . '/app/views/error.php';
    } ?>
</body>

</html>