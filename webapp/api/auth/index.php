<?php
declare(strict_types=1);
if (!defined('APP_WEBAPP_API_AUTH_INDEX_PHP_FIELD_KEY_KEY')) { define('APP_WEBAPP_API_AUTH_INDEX_PHP_FIELD_KEY_KEY', 'key'); }


require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";

// --- Настройки rate-limit ---
define('MAX_ATTEMPTS', 10);
define('BLOCK_TIME', 60 * 5); // 5 минут блокировка

header('Content-Type: application/json');

try {

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $key = strtolower(trim($input['access_key'] ?? ''));

    // -------------------------
    // Проверка синтаксиса UUID v5
    // -------------------------
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key)) {
        throw new Exception('Неверный формат ключа');
    }

    // -------------------------
    // Rate limit по IP
    // -------------------------
    $ip = $_SERVER['REMOTE_ADDR'];

    // таблица auth_attempts(ip, attempts, last_attempt)
    $attempt = DB::selectOne("SELECT attempts, last_attempt FROM auth_attempts WHERE ip = :ip", ['ip' => $ip]);
    $now = time();

    if ($attempt) {
        if ($attempt['attempts'] >= MAX_ATTEMPTS && ($now - strtotime($attempt['last_attempt'])) < BLOCK_TIME) {
            throw new Exception('Слишком много попыток, попробуйте позже');
        }
    }

    // -------------------------
    // 3Проверка ключа в базе
    // -------------------------
    $teacher = DB::selectOne(
        "SELECT name FROM users__teachers WHERE access_key = :key LIMIT 1",
        [\APP_WEBAPP_API_AUTH_INDEX_PHP_FIELD_KEY_KEY => $key]
    );

    if (!$teacher) {
        // Неудачная попытка
        if ($attempt) {
            DB::execute(
                "UPDATE auth_attempts SET attempts = attempts + 1, last_attempt = NOW() WHERE ip = :ip",
                ['ip' => $ip]
            );
        } else {
            DB::execute(
                "INSERT INTO auth_attempts(ip, attempts, last_attempt) VALUES(:ip,1,NOW())",
                ['ip' => $ip]
            );
        }
        throw new Exception('Неверный ключ');
    }

    DB::execute("DELETE FROM auth_attempts WHERE ip = :ip", ['ip' => $ip]);

    DB::execute(
        "UPDATE users__teachers SET key_activation_date = NOW(), key_usage_count = key_usage_count + 1 WHERE access_key = :key",
        ['key' => $key]
    );

    session_start();
    session_regenerate_id(true);

    $_SESSION['teacher'] = $teacher['name'];
    $_SESSION['auth_time'] = $now;

    setcookie(
        session_name(),
        session_id(),
        [
            'expires' => $now + 60 * 60 * 24 * 30,
            'path' => '/',
            'httponly' => true,
            'secure' => isset($_SERVER['HTTPS']),
            'samesite' => 'Lax'
        ]
    );

    $token = bin2hex(random_bytes(32));
    setcookie(
        'auth_teacher',
        $token,
        [
            'expires' => time() + 60 * 60 * 24 * 30,
            'path' => '/',
            'httponly' => false,
            'secure' => isset($_SERVER['HTTPS']),
            'samesite' => 'Lax'
        ]
    );
    setcookie('llt_time', '', time() - 3600, '/');

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}