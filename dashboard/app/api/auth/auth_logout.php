<?php

if (!defined('APP_DASHBOARD_APP_API_AUTH_AUTH_LOGOUT_PHP_FIELD_KEY_STATUS')) { define('APP_DASHBOARD_APP_API_AUTH_AUTH_LOGOUT_PHP_FIELD_KEY_STATUS', 'status'); }
session_start();
header('Content-Type: application/json; charset=utf-8');

require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";

try {
    Auth::logout();
    if (!empty($_GET["redirect"])) {
        header('Location: /');
    } else {
        echo json_encode([
            \APP_DASHBOARD_APP_API_AUTH_AUTH_LOGOUT_PHP_FIELD_KEY_STATUS => 'ok'
        ]);
    }
} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}