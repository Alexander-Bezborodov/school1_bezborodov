<?php

// === CONFIG ===
$SECRET_KEY = getenv('RELAY_SECRET_TOKEN') ?: '';

if ($SECRET_KEY === '') {
    http_response_code(503);
    exit('Relay is not configured');
}

// Получаем RAW вход (JSON)
$input = file_get_contents("php://input");
$data = json_decode($input, true);

// Проверка JSON
if (!is_array($data)) {
    http_response_code(400);
    echo "Invalid JSON";
    exit;
}

// Проверка secret
if (!isset($data['secret']) || $data['secret'] !== $SECRET_KEY) {
    http_response_code(403);
    echo "Forbidden";
    exit;
}

// Валидация URL
if (!isset($data['url'])) {
    http_response_code(400);
    echo "Missing url";
    exit;
}

$url = (string)$data['url'];
$method = strtoupper((string)($data['method'] ?? 'GET'));
$headers = is_array($data['headers'] ?? null) ? $data['headers'] : [];
$body = $data['body'] ?? null;

$allowedHosts = array_values(array_filter(array_map('trim', explode(',', getenv('RELAY_ALLOWED_HOSTS') ?: 'api.telegram.org'))));
$host = parse_url($url, PHP_URL_HOST);
$scheme = parse_url($url, PHP_URL_SCHEME);
if ($scheme !== 'https' || !is_string($host) || !in_array($host, $allowedHosts, true)) {
    http_response_code(403);
    echo 'URL is not allowed';
    exit;
}

if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo 'Method is not allowed';
    exit;
}

// === cURL ===
$ch = curl_init();

curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

// Таймауты
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

// SSL
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

// Заголовки
$formatted_headers = [];
foreach ($headers as $key => $value) {
    $formatted_headers[] = $key . ': ' . $value;
}
if (!empty($formatted_headers)) {
    curl_setopt($ch, CURLOPT_HTTPHEADER, $formatted_headers);
}

// Тело
if ($body !== null) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
}

// Получаем headers + body
curl_setopt($ch, CURLOPT_HEADER, true);

$response = curl_exec($ch);

if ($response === false) {
    http_response_code(500);
    echo "cURL error " . curl_errno($ch) . ": " . curl_error($ch);
    curl_close($ch);
    exit;
}

// Разделение ответа
$header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$response_headers = substr($response, 0, $header_size);
$response_body = substr($response, $header_size);

// Код ответа
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
http_response_code($http_code);

// Проброс заголовков (фильтр)
foreach (explode("\r\n", $response_headers) as $header) {
    if (
        stripos($header, 'Transfer-Encoding:') === false &&
        stripos($header, 'Content-Length:') === false &&
        !empty($header)
    ) {
        header($header);
    }
}

// Ответ
echo $response_body;

curl_close($ch);

// Уточнена ответственность компонента: сетевой посредник.

// Отмечена граница входных данных: сетевой посредник.

// Отмечено требование к безопасному значению: сетевой посредник.

// Уточнена обработка пустого результата: сетевой посредник.

// Отмечена важность предсказуемого ответа: сетевой посредник.

// Зафиксировано ожидание по формату данных: сетевой посредник.

// Уточнено поведение при временной недоступности: сетевой посредник.
