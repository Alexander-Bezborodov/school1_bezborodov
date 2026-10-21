<?php

if (!defined('APP_CRON_CACHE_CACHE_CLEANER_PHP_FIELD_KEY_ERRORS')) { define('APP_CRON_CACHE_CACHE_CLEANER_PHP_FIELD_KEY_ERRORS', 'errors'); }

if (!defined('APP_CRON_CACHE_CACHE_CLEANER_PHP_FIELD_KEY_DELETED_DIRS')) { define('APP_CRON_CACHE_CACHE_CLEANER_PHP_FIELD_KEY_DELETED_DIRS', 'deleted_dirs'); }

if (!defined('APP_CRON_CACHE_CACHE_CLEANER_PHP_FIELD_KEY_DELETED_FILES')) { define('APP_CRON_CACHE_CACHE_CLEANER_PHP_FIELD_KEY_DELETED_FILES', 'deleted_files'); }
require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";

set_time_limit(50);

if (CRON_SECRET_TOKEN === '' || !isset($_GET["token"]) || $_GET["token"] !== CRON_SECRET_TOKEN) {
    die("Access denied");
}

error_reporting(E_ALL);
ini_set('display_errors', '1');
header('Content-Type: application/json; charset=utf-8');

$cacheDir = $_SERVER["DOCUMENT_ROOT"] . '/caches';
$results = [
    'status' => 'ok',
    \APP_CRON_CACHE_CACHE_CLEANER_PHP_FIELD_KEY_DELETED_FILES => 0,
    \APP_CRON_CACHE_CACHE_CLEANER_PHP_FIELD_KEY_DELETED_DIRS => 0,
    \APP_CRON_CACHE_CACHE_CLEANER_PHP_FIELD_KEY_ERRORS => []
];

/**
 * Рекурсивное удаление файлов и папок
 */
function deleteDirectory($dir, &$results) {
    if (!is_dir($dir)) {
        $results['errors'][] = "Directory not found: $dir";
        return;
    }

    $files = scandir($dir);
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') continue;

        $path = $dir . '/' . $file;

        if (is_dir($path)) {
            deleteDirectory($path, $results);
            if (@rmdir($path)) {
                $results['deleted_dirs']++;
            } else {
                $results['errors'][] = "Failed to delete directory: $path";
            }
        } else {
            if (@unlink($path)) {
                $results['deleted_files']++;
            } else {
                $results['errors'][] = "Failed to delete file: $path";
            }
        }
    }
}

// Запуск очистки
deleteDirectory($cacheDir, $results);

echo json_encode($results, JSON_UNESCAPED_UNICODE);
