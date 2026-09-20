<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

// webhook.php
$target = getenv('RELAY_TARGET_URL') ?: '';

if ($target === '') {
    http_response_code(503);
    exit('Relay target is not configured');
}

// Инициализация cURL
$ch = curl_init($target);

// Базовые настройки
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $_SERVER['REQUEST_METHOD']);

// Заголовки
$headers = [];
foreach (getallheaders() as $key => $value) {
    // исключаем Host, чтобы не ломать запрос
    if (strtolower($key) === 'host')
        continue;

    $headers[] = "$key: $value";
}

curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

// Тело запроса (если есть)
$body = file_get_contents('php://input');

if (!empty($body)) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

// Выполнение запроса
$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

// Ошибка cURL
if ($response === false) {
    http_response_code(500);
    echo 'cURL error: ' . curl_error($ch);
    curl_close($ch);
    exit;
}

curl_close($ch);

// Возвращаем ответ Telegram
http_response_code($http_code);
echo $response;

// Уточнена ответственность компонента: передача расписания.

// Отмечена граница входных данных: передача расписания.

// Зафиксировано ожидаемое поведение при ошибке: передача расписания.

// Отмечено требование к безопасному значению: передача расписания.
