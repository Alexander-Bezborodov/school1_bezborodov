<?php

if (!defined('APP_DASHBOARD_APP_API_TEACHERS_UPDATE_CHAT_ID_PHP_FIELD_KEY_ACCESS_KEY')) { define('APP_DASHBOARD_APP_API_TEACHERS_UPDATE_CHAT_ID_PHP_FIELD_KEY_ACCESS_KEY', 'access_key'); }
require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";

error_reporting(E_ALL);
ini_set('display_errors', '1');

$user = Auth::requireAuth();

// Заголовки для JSON-ответа
header('Content-Type: application/json; charset=utf-8');

// Проверяем метод запроса
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Получаем и валидируем данные
$teacher = trim($_POST['teacher'] ?? '');
$chatId = trim($_POST['chat_id'] ?? '');

if ($teacher === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Teacher name is required']);
    exit;
}

// Проверка формата chat_id (только цифры и минус)
if ($chatId !== '' && !preg_match('/^-?\d+$/', $chatId)) {
    http_response_code(400);
    echo json_encode(['error' => 'Некорректный chat_id']);
    exit;
}

// --- Проверяем, существует ли chat_id в таблице users ---
if ($chatId !== '') {
    $userExists = DB::selectOne("SELECT chat_id FROM users WHERE chat_id = ?", [$chatId]);
    if (!$userExists) {
        http_response_code(400);
        echo json_encode(['error' => 'Учителя нет в чат-боте']);
        exit;
    }
}

try {
    // Приводим к нижнему регистру для поиска без учета регистра
    $teacherLower = mb_strtolower($teacher);

    // Проверяем, есть ли запись в users__teachers
    $existing = DB::selectOne("SELECT * FROM users__teachers WHERE LOWER(name) = ?", [$teacherLower]);

    if ($existing) {
        // Обновляем chat_id
        DB::execute(
            "UPDATE users__teachers SET chat_id = ? WHERE LOWER(name) = ?",
            [$chatId, $teacherLower]
        );
        $accessKey = $existing[\APP_DASHBOARD_APP_API_TEACHERS_UPDATE_CHAT_ID_PHP_FIELD_KEY_ACCESS_KEY] ?? null; // оставляем старый ключ
    } else {
        function uuid5($namespace, $name)
        {
            $nhex = str_replace(['-', '{', '}'], '', $namespace);
            $nstr = '';
            for ($i = 0; $i < strlen($nhex); $i += 2) {
                $nstr .= chr(hexdec($nhex[$i] . $nhex[$i + 1]));
            }

            $hash = sha1($nstr . $name);

            return sprintf(
                '%08s-%04s-%04x-%04x-%12s',
                substr($hash, 0, 8),
                substr($hash, 8, 4),
                (hexdec(substr($hash, 12, 4)) & 0x0fff) | 0x5000,
                (hexdec(substr($hash, 16, 4)) & 0x3fff) | 0x8000,
                substr($hash, 20, 12)
            );
        }

        $namespace = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';
        $name = $teacher . '_' . $chatId . '_' . random_bytes(4);
        $accessKey = uuid5($namespace, $name);

        DB::insert(
            "INSERT INTO users__teachers (name, chat_id, access_key) VALUES (?, ?, ?)",
            [$teacher, $chatId, $accessKey]
        );
    }

    echo json_encode([
        'success' => true,
        'chat_id' => $chatId,
        'access_key' => $accessKey
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error', 'details' => $e->getMessage()]);
}