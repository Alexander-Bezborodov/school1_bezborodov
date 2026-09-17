<?php
class DB
{
    /** @var PDO|null */
    private static ?PDO $pdo = null;

    private static function connect(): PDO
    {
        if (self::$pdo === null) {
            $host = getenv('DB_HOST') ?: '127.0.0.1';
            $name = getenv('DB_NAME') ?: '';
            $user = getenv('DB_USER') ?: '';
            $pass = getenv('DB_PASS') ?: '';
            $charset = getenv('DB_CHARSET') ?: 'utf8mb4';

            if ($name === '' || $user === '') {
                throw new RuntimeException('Database environment variables are not configured');
            }

            $dsn = "mysql:host={$host};dbname={$name};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            self::$pdo = new PDO($dsn, $user, $pass, $options);
        }
        return self::$pdo;
    }

    /** SELECT — все строки */
    public static function select(string $sql, array $params = []): array
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** SELECT — одна строка */
    public static function selectOne(string $sql, array $params = []): ?array
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** INSERT — возвращает ID для одной строки или сколько строк вставлено для множественной вставки */
    public static function insert(string $sql, array $params = [])
    {
        if (!is_array($params))
            return false;
        if (empty($params))
            return false;

        $stmt = self::connect()->prepare($sql);

        // множественная вставка
        if (isset($params[0]) && is_array($params[0])) {
            $rows = 0;
            foreach ($params as $row) {
                $stmt->execute($row);
                $rows++;
            }
            return $rows;
        } else {
            $stmt->execute($params);
            return self::connect()->lastInsertId();
        }
    }

    /** UPDATE / DELETE — возвращает количество строк */
    public static function execute(string $sql, array $params = []): int
    {
        $stmt = self::connect()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /** Транзакции */
    public static function begin(): void
    {
        self::connect()->beginTransaction();
    }

    public static function commit(): void
    {
        self::connect()->commit();
    }

    public static function rollback(): void
    {
        self::connect()->rollBack();
    }

    /** Получить PDO напрямую */
    public static function pdo(): PDO
    {
        return self::connect();
    }
}

// Уточнена ответственность компонента: подключение к базе.

// Отмечена граница входных данных: подключение к базе.
