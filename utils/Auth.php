<?php
declare(strict_types=1);

use JetBrains\PhpStorm\NoReturn;

require_once __DIR__ . "/../functions/core.php";

final class Auth
{
    private const FIELD_KEY_DOMAIN = 'domain';

    private const FIELD_KEY_PATH = 'path';

    private const FIELD_KEY_EXPIRES = 'expires';

    private static string $secretKey;
    private static string $cipher = 'AES-256-CBC';
    private static int $cookieLifetime = 60 * 60 * 24 * 30; // 30 дней

    // Инициализация секретного ключа
    private static function init(): void
    {
        if (isset(self::$secretKey)) {
            return;
        }
        $envKey = getenv('BZB_TT_SECRET_KEY');
        if (!is_string($envKey) || trim($envKey) === '') {
            throw new RuntimeException('BZB_TT_SECRET_KEY is not configured');
        }
        $key = $envKey;

        // Требуем 32-байтовый ключ для AES-256
        $raw = hex2bin(preg_replace('/[^0-9a-fA-F]/', '', $key));
        if ($raw === false || strlen($raw) < 32) {
            // Если ключ короче — расширяем безопасным способом
            $raw = hash('sha256', $key, true);
        }

        // Используем бинарный ключ
        self::$secretKey = $raw;
    }

    // ----------------------
    // Шифрование с аутентификацией (Encrypt-then-MAC)
    // ----------------------
    private static function encrypt(string $data): string
    {
        self::init();

        $ivLen = openssl_cipher_iv_length(self::$cipher);
        $iv = random_bytes($ivLen);
        $ciphertext = openssl_encrypt($data, self::$cipher, self::$secretKey, OPENSSL_RAW_DATA, $iv);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed');
        }

        // HMAC по IV + ciphertext
        $hmac = hash_hmac('sha256', $iv . $ciphertext, self::$secretKey, true);

        return base64_encode($iv . $hmac . $ciphertext);
    }

    private static function decrypt(string $data): ?string
    {
        self::init();

        $raw = base64_decode($data, true);
        if ($raw === false) {
            return null;
        }

        $ivLen = openssl_cipher_iv_length(self::$cipher);
        $hmacLen = 32; // sha256

        if (strlen($raw) < ($ivLen + $hmacLen + 1)) {
            return null;
        }

        $iv = substr($raw, 0, $ivLen);
        $hmac = substr($raw, $ivLen, $hmacLen);
        $ciphertext = substr($raw, $ivLen + $hmacLen);

        $calculated = hash_hmac('sha256', $iv . $ciphertext, self::$secretKey, true);
        // безопасное сравнение
        if (!hash_equals($calculated, $hmac)) {
            return null;
        }

        $decrypted = openssl_decrypt($ciphertext, self::$cipher, self::$secretKey, OPENSSL_RAW_DATA, $iv);

        return $decrypted === false ? null : $decrypted;
    }

    // ----------------------
    // Вспомогательные: установка cookie с безопасными флагами
    // ----------------------
    private static function setSecureCookie(string $name, string $value, int $expires): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie($name, $value, [
            self::FIELD_KEY_EXPIRES => $expires,
            self::FIELD_KEY_PATH => '/',
            self::FIELD_KEY_DOMAIN => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        // Обновляем локальный массив чтобы дальнейший код видел новые значения
        $_COOKIE[$name] = $value;
    }

    private static function clearCookie(string $name): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie($name, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        unset($_COOKIE[$name]);
    }

    // ----------------------
    // Login: теперь мы не сохраняем хеш пароля в cookie.
    // Вместо этого формируем подпись (HMAC) для auth_key и шифруем account_id.
    // ----------------------
    public static function login(string $token, string $password): bool
    {
        // По безопасности — ограничиваем длину входных данных
        $token = substr($token, 0, 64);

        $user = DB::selectOne("SELECT * FROM `admin` WHERE `token` = ?", [$token]);
        if (!$user) {
            return false;
        }

        // Проверяем пароль
        $storedHash = $user['password'] ?? null;
        if (!$storedHash || !password_verify($password, $storedHash)) {
            return false;
        }

        // Регенерируем session id для защиты от фиксации сессии
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        // Подготавливаем полезную нагрузку auth_key (без пароля)
        $payload = [
            'id' => (int)$user['id'],
            'token' => $token,
            'exp' => time() + self::$cookieLifetime,
        ];
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            return false;
        }

        // Подписываем HMAC и сохраняем base64(payload).signature
        $signature = hash_hmac('sha256', $payloadJson, self::getSigningKey());
        $authKey = base64_encode($payloadJson) . "." . $signature;

        // Шифруем id отдельно
        $encryptedId = self::encrypt((string)$user['id']);

        $expires = time() + self::$cookieLifetime;

        self::setSecureCookie('account_id', $encryptedId, $expires);
        self::setSecureCookie('auth_key', $authKey, $expires);

        return true;
    }

    private static function getSigningKey(): string
    {
        self::init();
        // Дополнительное разделение ключей — используем HKDF-like derivation
        return hash_hmac('sha256', 'auth-signing', self::$secretKey, true);
    }

    // ----------------------
    // requireAuth — более строгая и оптимизированная проверка
    // ----------------------
    public static function requireAuth(bool $checkAdmin = false, bool $checkUser = false, bool $jsonHeader = false, bool $exit = true): array
    {
        if ($jsonHeader) {
            header('Content-Type: application/json; charset=utf-8');
        }

        $user = self::check();
        $callbackMessage = null;

        if (!$user) {
            $callbackMessage = 'Authorization error';
        }

        if (!empty($user['ban']) && (int)$user['ban'] === 1) {
            $callbackMessage = 'Your account has been blocked. Contact technical support.';
        }

        // Права: используем значения из $user вместо повторных DB-запросов
        $isAdmin = isset($user['role']) && $user['role'] === 'ADMIN';
        if (($checkAdmin && !$isAdmin && !$checkUser) || ($isAdmin && $checkUser && !$checkAdmin)) {
            $callbackMessage = 'Insufficient permissions';
        }

        // Проверяем account_id и его расшифровку
        if (empty($_COOKIE['account_id']) || self::decrypt((string)$_COOKIE['account_id']) === null) {
            $callbackMessage = 'Authorization has expired';
        }

        if ($callbackMessage !== null && $exit) {
            self::respondError($callbackMessage, 403);
        } else if ($callbackMessage !== null) {
            $user = [];
        }

        return $user;
    }

    // ----------------------
    // check — проверка авторизации. Возвращает user array или false
    // ----------------------
    public static function check()
    {
        if (empty($_COOKIE['auth_key']) || empty($_COOKIE['account_id'])) {
            return false;
        }

        // Декодируем и проверяем подпись
        $authParts = explode('.', (string)$_COOKIE['auth_key']);
        if (count($authParts) !== 2) {
            return false;
        }

        $payloadJson = base64_decode($authParts[0], true);
        $signature = $authParts[1];
        if ($payloadJson === false || $signature === false) {
            return false;
        }

        $expected = hash_hmac('sha256', $payloadJson, self::getSigningKey());
        if (!hash_equals($expected, $signature)) {
            return false;
        }

        $decoded = json_decode($payloadJson, true);
        if (!$decoded || empty($decoded['id']) || empty($decoded['token']) || empty($decoded['exp'])) {
            return false;
        }

        // Проверка срока жизни
        if ((int)$decoded['exp'] < time()) {
            return false;
        }

        // Сверяем, что id из payload совпадает с расшифрованным account_id
        $decryptedId = self::decrypt((string)$_COOKIE['account_id']);
        if ($decryptedId === null || (string)$decoded['id'] !== (string)$decryptedId) {
            return false;
        }

        // Проверяем пользователя в БД — теперь без сравнения хеша пароля
        $user = DB::selectOne(
            "SELECT * FROM `admin` WHERE `id` = ? AND `token` = ? LIMIT 1",
            [(int)$decoded['id'], $decoded['token']]
        );

        return $user ?? false;
    }

    // ----------------------
    // logout
    // ----------------------
    public static function logout(): void
    {
        self::clearCookie('account_id');
        self::clearCookie('auth_key');

        // Optionally уничтожаем сессию
        if (session_status() === PHP_SESSION_ACTIVE) {
            // Обязательно удалить данные сессии
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'] ?? false, $params['httponly'] ?? false);
            }
            session_destroy();
        }
    }

    #[NoReturn]
    public static function respondError(string $message, int $code = 401): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'error',
            'callback' => $message
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
