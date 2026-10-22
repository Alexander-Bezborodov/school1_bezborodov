<?php

if (!defined('APP_DASHBOARD_APP_API_AUTH_AUTH_PHP_FIELD_KEY_CALLBACK')) { define('APP_DASHBOARD_APP_API_AUTH_AUTH_PHP_FIELD_KEY_CALLBACK', 'callback'); }

if (!defined('APP_DASHBOARD_APP_API_AUTH_AUTH_PHP_FIELD_KEY_MESSAGE')) { define('APP_DASHBOARD_APP_API_AUTH_AUTH_PHP_FIELD_KEY_MESSAGE', 'message'); }

if (!defined('APP_DASHBOARD_APP_API_AUTH_AUTH_PHP_FIELD_KEY_STATUS')) { define('APP_DASHBOARD_APP_API_AUTH_AUTH_PHP_FIELD_KEY_STATUS', 'status'); }
session_start();

require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([\APP_DASHBOARD_APP_API_AUTH_AUTH_PHP_FIELD_KEY_STATUS => 'error', \APP_DASHBOARD_APP_API_AUTH_AUTH_PHP_FIELD_KEY_MESSAGE => 'Метод не разрешён']);
    exit;
}

$token = trim($_POST['username'] ?? '');
$password = trim($_POST['password'] ?? '');

if (!$token || !$password) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Заполните все поля']);
    exit;
}

try {
    if (Auth::login($token, $password)) {
        echo json_encode([
            'status' => 'ok',
            \APP_DASHBOARD_APP_API_AUTH_AUTH_PHP_FIELD_KEY_CALLBACK => 'Вы успешно вошли в систему'
        ]);
    } else {
        http_response_code(401);
        echo json_encode([
            'status' => 'error',
            'callback' => 'Неверный логин или ключ'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'callback' => 'Ошибка сервера'
    ]);
}
