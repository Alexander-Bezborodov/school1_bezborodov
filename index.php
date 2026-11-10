<?php
declare(strict_types=1);
if (!defined('APP_INDEX_PHP_FIELD_KEY_POLICY_CONFIRMED')) { define('APP_INDEX_PHP_FIELD_KEY_POLICY_CONFIRMED', 'policy_confirmed'); }

date_default_timezone_set('Asia/Yekaterinburg');

require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";

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
        'path' => $cookieParams['path'] ?? '/',
        'domain' => $cookieParams['domain'] ?? '',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax', // или 'Strict' при жёсткой политике
    ]);
    session_start();
}

function v(string $path): string
{
    $full = realpath($_SERVER["DOCUMENT_ROOT"] . $path);
    if ($full === false || !is_file($full)) {
        return '';
    }
    return (string) filemtime($full);
}

// ----------------------
// Окончательная генерация HTML
// ----------------------
// Заголовок страницы и мета — безопасно выводим
$pageTitle = 'Первая';
$pageDescription = '📌 Расписание Первой - актуальное расписание уроков. ✅ Будьте всегда в курсе занятий и важных обновлений.';

$libScripts = [
    // "https://cdnjs.cloudflare.com/ajax/libs/pulltorefreshjs/0.1.22/index.umd.min.js",
    "/webapp/src/swiper-bundle.min.js",
    "/webapp/src/popper.min.js",
    "/webapp/src/tippy-bundle.umd.min.js",
    "/webapp/src/fancybox.umd.js",
    "/webapp/src/main.js",
]; ?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=no">
    <meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <link rel="stylesheet" href="/webapp/css/styles.min.css?v=<?= v('/webapp/css/styles.min.css') ?>">
    <link rel="apple-touch-icon" sizes="180x180" href="/webapp/favicon/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/webapp/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/webapp/favicon/favicon-16x16.png">
    <link rel="manifest" href="/webapp/favicon/site.webmanifest">
    <?php if (($_COOKIE["classes"] ?? null) != null): ?>
        <script async src="https://www.googletagmanager.com/gtag/js?id=G-86HZQZ41E7"></script>
        <script>
            window.dataLayer = window.dataLayer || [];
            function gtag() { dataLayer.push(arguments); }
            gtag('js', new Date());
            gtag('config', 'G-86HZQZ41E7');
        </script>
    <?php endif; ?>
</head>

<body>
    <?php
    $policyConfirmed = isset($_COOKIE[\APP_INDEX_PHP_FIELD_KEY_POLICY_CONFIRMED]) && $_COOKIE['policy_confirmed'] === '1';
    if (!$policyConfirmed): ?>
        <div class="confirm confirm--cookie confirm--show">
            <div class="confirm__content timetable__item item--clear">
                <svg width="134" height="309" viewBox="0 0 134 309" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M133.146 300.306L42.0627 308.343L40.4603 77.8771L1.34326 65.6329L133.146 0.803131V300.306Z"
                        stroke="var(--color-title)" stroke-width="2" fill="var(--color-bg)"></path>
                </svg>
                <div class="timetable__item-title">
                    Это приложение Первой школы.
                </div>
                <div class="timetable__item-text confirm__text">
                    Мы собираем и обрабатываем данные браузера и cookie для работы сайта. Нажимая «Продолжить»,
                    вы соглашаетесь с этим.
                </div>
                <button class="confirm__button js-policy-confirm cyber">Продолжить</button>
            </div>
        </div>
    <?php endif; ?>
    <?php
    try {
        include_once $_SERVER["DOCUMENT_ROOT"] . "/webapp/pages/main.php";
    } catch (Throwable $ex) {
        http_response_code(500);
        include_once $_SERVER["DOCUMENT_ROOT"] . "/webapp/pages/error.php";
    } ?>
</body>

</html>