<?php

use Shuchkin\Logger;

require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";

class Bot
{
    private string $token;
    private array $commands = [];
    private array $callbackHandlers = [];
    private Logger $logger;
    private Relay $relay;
    private array $ADMIN_CHAT_LIST = [];
    private ?array $user = null;

    public function __construct(string $token, Logger $logger)
    {
        $this->token = $token;
        $this->logger = $logger;
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_BOT_TOKEN is not configured');
        }
        $adminChatIds = getenv('ADMIN_CHAT_IDS') ?: '';
        $this->ADMIN_CHAT_LIST = array_values(array_filter(array_map('trim', explode(',', $adminChatIds))));
        $this->registerCommands();
        $this->registerCallbacks();
        $this->relay = new Relay(
            getenv('RELAY_URL') ?: '',
            getenv('RELAY_SECRET_TOKEN') ?: '',
            $this->logger
        );
    }

    private function tgUrl($method)
    {
        return "https://api.telegram.org/bot{$this->token}/{$method}";
    }

    private function registerCommands()
    {
        $this->commands['/start'] = new OptParallelCommand($this);
        $this->commands['/cancel'] = new CancelTimetableCommand($this);
        $this->commands['/def'] = new ClassCommonTimetableCommand($this);
        $this->commands['/set'] = new OptParallelCommand($this);
        $this->commands['/settings'] = new SettingsCommand($this);
        $this->commands['/help'] = new HelpCommand($this);
    }

    private function registerCallbacks()
    {
        $this->callbackHandlers['policy_confirmed'] = function ($callback) {
            $message = $callback['message'];
            $chatId = $message['chat']['id'];
            $messageId = $message['message_id'];

            DB::execute("
                INSERT INTO users__policy (chat_id, confirm) 
                VALUES (:id, 1)
                ON DUPLICATE KEY UPDATE confirm = 1
            ", ['id' => $chatId]);

            $this->deleteMessage($chatId, $messageId);
            (new OptParallelCommand($this))->execute($message);
        };

        $this->callbackHandlers['close'] = function ($callback) {
            $message = $callback['message'];
            $chatId = $message['chat']['id'];
            $messageId = $message['message_id'];
            $this->deleteMessage($chatId, $messageId);
            $this->deleteMessage($chatId, $messageId - 1);
        };
    }

    public function handleUpdate(array $update)
    {
        $this->logger->logArray($update, 'Incoming update:');

        // --- Обработка сообщений ---
        if (isset($update['message'])) {
            $message = $update['message'];
            $chatId = $message['chat']['id'];
            try {
                $this->managementMessage($chatId, $message);
            } catch (Exception $e) {
                if ($this->isAdmin($chatId)) {
                    $this->sendMessage($chatId, "[handleUpdate] " . $e->getMessage());
                }
            }
        }

        // --- Обработка callback_query ---
        if (isset($update['callback_query'])) {
            $callback = $update['callback_query'];
            $callbackId = $callback['id'];
            $data = $callback['data'];

            // Жёсткие callback_handlers
            if (isset($this->callbackHandlers[$data])) {
                $this->callbackHandlers[$data]($callback);
            } elseif (substr($data, 0, 4) === 'cmd:') {
                $this->handleDynamicCallback($callback, substr($data, 4));
            }

            $this->answerCallback($callbackId);
        }
    }

    private function handleDynamicCallback(array $callback, string $payload)
    {
        $chatId = $callback['message']['chat']['id'];
        $messageId = $callback['message']['message_id'];

        DB::execute("UPDATE users SET action_date=NOW(), activity_count=activity_count+1 WHERE chat_id = ?", [$chatId]);

        $payload = urldecode($payload);
        $parts = explode('_', $payload);

        if (($parts[0] ?? '') === '') {
            $this->sendMessage($chatId, "❌ Ошибка: некорректные данные callback");
            return;
        }

        $type = array_shift($parts);
        $params = $parts;

        switch ($type) {
            case "timetable":
                $class = $params[0] ?? null;
                $date = $params[1] ?? (new DateTime())->format('Y-m-d');

                if (!$class) {
                    $this->sendMessage($chatId, "❌ Ошибка: класс не указан");
                    return;
                }

                $user = DB::selectOne("
                    SELECT u.*, t.name AS teacher_name
                    FROM users u
                    LEFT JOIN users__teachers t ON t.chat_id = u.chat_id
                    WHERE u.chat_id = ?
                    LIMIT 1
                ", [$chatId]);
                $message = $callback['message'];

                (new GetTimetableCommand($this))->execute($user, $message, $date, $class, $messageId);
                break;
            case "common-timetable":
                $class = $params[0] ?? null;
                $day = $params[1] ?? null;

                if (!$class || !$day) {
                    $this->sendMessage($chatId, "❌ Ошибка: класс или день не указан");
                    return;
                }

                $user = DB::selectOne("
                    SELECT u.*, t.name AS teacher_name
                    FROM users u
                    LEFT JOIN users__teachers t ON t.chat_id = u.chat_id
                    WHERE u.chat_id = ?
                    LIMIT 1
                ", [$chatId]);
                $message = $callback['message'];

                (new GetCommonTimetableCommand($this))->execute($user, $message, $day, $class, $messageId);
                break;
            case "common-class-timetable":
                $message = $callback['message'];
                $user = DB::selectOne("
                    SELECT u.*
                    FROM users u
                    WHERE u.chat_id = ?
                    LIMIT 1
                ", [$chatId]);
                (new ClassCommonTimetableCommand($this))->execute($user, $message, $messageId);
                break;
            case "common-week-timetable":
                $message = $callback['message'];
                $class = $params[0] ?? null;
                (new WeekCommonTimetableCommand($this))->execute($message, $class, $messageId);
                break;
            case "settings":
                $message = $callback['message'];

                $type = $params[0] ?? null;
                $action = $params[1] ?? "toggle";

                if ($type === "format" && $action === "toggle") {
                    DB::execute(
                        "UPDATE users 
                             SET format = CASE WHEN format = 'TEXT' THEN 'IMAGE' ELSE 'TEXT' END 
                             WHERE chat_id = ?",
                        [$chatId]
                    );
                }

                if ($type === "mailing" && $action === "toggle") {
                    DB::execute(
                        "UPDATE users 
                             SET mailing = CASE WHEN mailing = 1 THEN 0 ELSE 1 END 
                             WHERE chat_id = ?",
                        [$chatId]
                    );
                }

                if ($type === "teacher-only" && $action === "toggle") {
                    DB::execute(
                        "UPDATE users 
                             SET hide_classes = CASE WHEN hide_classes = 1 THEN 0 ELSE 1 END 
                             WHERE chat_id = ?",
                        [$chatId]
                    );
                }

                if ($type === "font" && $action === "toggle") {
                    DB::execute(
                        "UPDATE users 
                             SET font = CASE WHEN font = 'times_regular.ttf' THEN NULL ELSE 'times_regular.ttf' END 
                             WHERE chat_id = ?",
                        [$chatId]
                    );
                }

                (new SettingsCommand($this))->execute($message, $messageId);
                break;
            default:
                $this->sendMessage($chatId, "❌ Неизвестный тип команды: $type");
        }
    }

    /**
     * @throws DateMalformedStringException
     * @throws Exception
     */
    private function managementMessage($chatId, $message)
    {
        $username = $message['chat']['username'] ?? '';
        $text = $message['text'] ?? '';
        $textEl = trim($text);

        if (IS_MAINTENANCE_MODE && !$this->isAdmin($chatId)) {
            $this->sendMessage($chatId, "🛠️ Технические работы не влияют на рассылку расписания, но функции пока что будут ограничены");
            return;
        }

        $policy = DB::selectOne("SELECT * FROM users__policy WHERE chat_id = ? LIMIT 1", [$chatId]);

        if ($policy === null || (int)($policy['confirm'] ?? 0) === 0) {
            $keyboard = Keyboard::inline([
                [Keyboard::button("Продолжить", "policy_confirmed")],
            ]);
            $this->sendMessage($chatId, "👋 <b>Это Telegram-бот Первой школы для получения расписания.</b> После нажатия кнопки «Продолжить» бот сохранит информацию о вашем аккаунте и действиях внутри бота для корректной работы", $keyboard);
            return;
        }

        $this->user = DB::selectOne("
            SELECT u.*, t.name AS teacher_name
            FROM users u
            LEFT JOIN users__teachers t ON t.chat_id = u.chat_id
            WHERE u.chat_id = ?
            LIMIT 1
        ", [$chatId]);

        DB::execute("UPDATE users SET username=?, action_date=NOW(), activity_count=activity_count+1 WHERE chat_id = ?", [$username, $chatId]);

        if (preg_match('/^\/mail\s+(.+)/', $text, $matches) || $textEl == "/mail") {
            (new ReportCommand($this))->execute($message, $matches[1] ?? "");
            return;
        }

        if (preg_match('/^\/mailto\s+(\d+)\s+(.+)/', $text, $matches)) {
            (new MailToCommand($this))->execute($message, $matches[1], $matches[2]);
            return;
        }

        if ($this->user["access"] == 0 && $this->user !== null) {
            $this->sendMessage($chatId, "❌ Печально, но ваш аккаунт был занесён в чёрный список по соображениям безопасности. Блокировка НЕ влияет на получение расписания, но функции будут ограничены. <b>Для обжалования используйте команду /mail</b>");
            return;
        }

        if ($textEl == trim("Выбрать другой класс") || $textEl == trim("Выбрать другую параллель") || $textEl == trim("✏️ Другой класс")) {
            (new OptParallelCommand($this))->execute($message);
            return;
        }

        if ($textEl == trim("⚙️ Настройки") && $this->getUser() != null) {
            (new SettingsCommand($this))->execute($message);
            return;
        }

        if ($textEl == trim("👁 Смотреть статистику") && $this->isAdmin($chatId)) {
            (new StatsCommand($this))->execute($message);
            return;
        }

        if (preg_match('/^\/(ban|unban)\s+(\S+)/', $text, $matches) && $this->isAdmin($chatId)) {
            $action = $matches[1] === 'ban';
            (new BanCommand($this))->execute($message, $matches[2], $action);
            return;
        }

        if ($textEl == trim("Смотреть полное расписание")) {
            (new ClassCommonTimetableCommand($this))->execute($message);
            return;
        }

        if ($textEl == trim('☑️ Оставить без изменений') && $this->getUser() != null) {
            $this->sendMessage($chatId, "👌 Не вопрос", $this->getMenuKeyboard($chatId));
            return;
        }

        if ($textEl == trim('Выбрать параллель') && $this->getUser() != null) {
            $this->sendMessage($chatId, "✍️ Чтобы добавить параллель, напишите в чат номер класса параллели, например — \"10\" или несколько — \"10, 11\". Для классов и параллелей — \"10, 11В\".");
            return;
        }

        if ($textEl == trim('Выбрать несколько классов') && $this->getUser() != null) {
            $this->sendMessage($chatId, "✍️ Чтобы добавить несколько классов, напишите их через запятую в чат, например: \"10А, 11В\". Для параллелей пишите только номер класса, например: \"10, 11В\".");
            return;
        }

        if (($textEl == trim("Смотреть на сегодня") || $textEl == trim("✍️ На сегодня")) && $this->getUser() != null) {
            $this->getTimetable($message, true);
            return;
        }

        if (($textEl == trim("Смотреть на завтра") || $textEl == trim("📝 На завтра")) && $this->getUser() != null) {
            $this->getTimetable($message, false);
            return;
        }

        if (preg_match('/((https?:\/\/)|(www\.))[^\s]+/i', $text)) {
            $map = [
                "link" => $text,
                $this->getUser()
            ];
            $this->alertAdmin($map);
            $this->sendMessage($chatId, "🤨 Ссылка? Напишите цифру класса, чтобы сменить его, если это нужно..");
            return;
        }

        if (preg_match('/^(?:[1-9]|1[0-1])\s*класс$/ui', $textEl)) {
            (new OptClassCommand($this))->execute($message);
            return;
        }

        if (preg_match('/^(-?(?:[1-9]|1[0-1])[A-Za-zА-Яа-я]|-?(?:[1-9]|1[0-1]))(?:[\s,]+(-?(?:[1-9]|1[0-1])[A-Za-zА-Яа-я]|-?(?:[1-9]|1[0-1])))*$/u', trim($textEl))) {
            if (substr(trim($textEl), 0, 1) === '-' && preg_match_all('/-/', $textEl) > 1) {
                $this->sendMessage($message['chat']['id'], "❌ Можно выбрать только один класс для скрытого просмотра");
                return;
            }

            (new SetClassCommand($this))->execute($this->getUser(), $message);
            return;
        }

        if ($this->isAdmin($chatId) && mb_strlen($textEl) >= 3) {
            $teacherQuery = DB::selectOne("SELECT name FROM users__teachers WHERE name LIKE ? LIMIT 1", ["%$text%"]);
            if (count($teacherQuery) > 0) {
                (new GetTeacherTimetableCommand($this))->execute($this->getUser(), $message, $teacherQuery["name"]);
                return;
            }
        }

        if (isset($this->commands[$text])) {
            $this->commands[$text]->execute($message);
        } else {
            $keyboard = null;
            if ($this->getUser() !== null) {
                $keyboard = $this->getMenuKeyboard($chatId);
            }
            (new HelpCommand($this))->execute($message, $keyboard);
        }
    }

    /**
     * @throws DateMalformedStringException
     */
    public function getTimetable($message, $today, $user = null, bool $selection = false, bool $cron = false)
    {
        $dateTime = new DateTime();
        $date = $dateTime->format('Y-m-d');

        if (!$today) {
            $date = getNextDayDate();
        }
        if ($user === null) {
            $user = $this->getUser();
        }

        //writeLogObj($user);

        (new GetTimetableCommand($this))->execute($user, $message, $date, null, null, $selection, $cron);
    }

    public function getUser(): ?array
    {
        return $this->user;
    }

    public function getMenuKeyboard($chatId): array
    {
        $keyboard = ["Смотреть на сегодня", "Смотреть на завтра"];
        $keyboard = array_chunk(
            array_map(fn($button) => Keyboard::replyButton($button), $keyboard),
            3
        );
        $keyboard[] = [Keyboard::replyButton("Смотреть полное расписание")];
        if ($this->isAdmin($chatId)) {
            $keyboard[] = [Keyboard::replyButton("👁 Смотреть статистику")];
        }
        $keyboard[] = [Keyboard::replyButton("⚙️ Настройки"), Keyboard::replyButton("✏️ Другой класс")];
        return Keyboard::reply($keyboard);
    }

    // --- Пример метода sendMessage ---
    public function sendMessage($chatId, $text, $keyboard = null, $botToken = null): array
    {
        if ($botToken === null) {
            $botToken = $this->token;
        }

        if (is_array($text)) {
            $text = "<pre>" .
                htmlspecialchars(json_encode($text, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))
                . "</pre>";
        }

        $data = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        if ($keyboard) {
            $data['reply_markup'] = $keyboard;
        }

        $response = $this->relay->request(
            "https://api.telegram.org/bot{$botToken}/sendMessage",
            'POST',
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_UNICODE)
        );

        $this->logger->log("Sent message | Response: $response");

        return $data;
    }

    public function editMessage($chatId, $messageId, string $text, $keyboard = null): bool
    {
        $data = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
            'parse_mode' => 'HTML'
        ];

        if ($keyboard) {
            $data['reply_markup'] = $keyboard;
        }

        $response = $this->relay->request(
            $this->tgUrl('editMessageText'),
            'POST',
            ['Content-Type' => 'application/json'],
            json_encode($data)
        );

        $result = json_decode($response, true);

        return isset($result['ok']) && $result['ok'];
    }

    public function deleteMessage($chatId, $messageId): bool
    {
        $data = [
            'chat_id' => $chatId,
            'message_id' => $messageId
        ];

        $response = $this->relay->request(
            $this->tgUrl('deleteMessage'),
            'POST',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($data)
        );

        $result = json_decode($response, true);

        return isset($result['ok']) && $result['ok'];
    }

    public function sendPhotoByFilePath($chatId, string $filePath, string $caption = '', array $keyboard = null)
    {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }

        $url = $this->tgUrl('sendPhoto');

        $postFields = [
            'chat_id' => $chatId,
            'photo' => new CURLFile($filePath),
            'caption' => $caption,
            'parse_mode' => 'HTML'
        ];

        if ($keyboard) {
            $postFields['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_TIMEOUT => 10
        ]);

        $response = curl_exec($ch);

        if ($response === false) {
            throw new Exception(curl_error($ch));
        }

        curl_close($ch);

        return json_decode($response, true);
    }

    public function editPhotoAndCaption($chatId, int $messageId, string $newFilePath, string $newCaption = '', array $keyboard = null)
    {
        if (!file_exists($newFilePath)) {
            $this->logger->log("Файл не найден: $newFilePath");
            return false;
        }

        $url = "https://api.telegram.org/bot{$this->token}/editMessageMedia";

        $media = [
            'type' => 'photo',
            'media' => 'attach://new_photo',
            'caption' => $newCaption,
            'parse_mode' => 'HTML'
        ];

        $postFields = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'media' => json_encode($media, JSON_UNESCAPED_UNICODE),
            'new_photo' => new CURLFile($newFilePath)
        ];

        if ($keyboard) {
            $postFields['reply_markup'] = json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5); // таймаут

        //        // критично важно
//        curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
//        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: multipart/form-data']);

        try {
            $response = curl_exec($ch);
            if ($response === false) {
                $err = curl_error($ch);
                $this->logger->log("cURL error while editing photo: $err");
                curl_close($ch);
                return false;
            }
            curl_close($ch);

            $json = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->log("Failed to decode Telegram response: $response");
                return false;
            }

            if (!isset($json['ok']) || $json['ok'] !== true) {
                $desc = $json['description'] ?? 'Unknown error';
                $this->logger->log("Telegram API error while editing photo: $desc");
                return false;
            }

            $this->logger->log("Photo edited successfully in message $messageId of chat $chatId");
            return $json;
        } catch (\Exception $e) {
            $this->logger->log("Exception while editing photo: " . $e->getMessage());
            return false;
        }
    }

    public function getChat($chatId)
    {
        $response = $this->relay->request(
            $this->tgUrl("getChat?chat_id={$chatId}"),
            'GET'
        );

        return json_decode($response, true);
    }

    public function answerCallback($callbackId, $text = '', $showAlert = false)
    {
        $data = [
            'callback_query_id' => $callbackId,
            'text' => $text,
            'show_alert' => $showAlert
        ];

        $this->relay->request(
            $this->tgUrl('answerCallbackQuery'),
            'POST',
            ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($data)
        );
    }

    public function isAdmin($chatId): bool
    {
        return in_array($chatId, $this->ADMIN_CHAT_LIST);
    }

    public function alertAdmin($string)
    {
        $this->sendMessage($this->ADMIN_CHAT_LIST[0], $string);
    }

    public function getFontByUser($user): array
    {
        $fontsDir = $_SERVER["DOCUMENT_ROOT"] . "/fonts";

        if (!is_dir($fontsDir)) {
            $fontsDir = __DIR__ . "/../fonts";
        }

        // Названия стандартных шрифтов
        $defaultRegularName = "sf_pro_display_regular.otf";
        $defaultBoldName = "sf_pro_display_medium.otf";

        $defaultRegular = $fontsDir . "/" . $defaultRegularName;
        $defaultBold = $fontsDir . "/" . $defaultBoldName;

        $userFont = $user["font"] ?? null;

        if (!$userFont) {
            return [
                'regular' => $defaultRegular,
                'bold' => $defaultBold,
                'custom' => false
            ];
        }

        $regularPath = $fontsDir . "/" . $userFont;
        $boldPath = $fontsDir . "/" . $userFont;

        // Проверяем существование файла
        if (file_exists($regularPath) && file_exists($boldPath)) {

            // custom = true, если шрифт НЕ стандартный
            $isCustom =
                basename($regularPath) !== $defaultRegularName ||
                basename($boldPath) !== $defaultBoldName;

            return [
                'regular' => $regularPath,
                'bold' => $boldPath,
                'custom' => $isCustom
            ];
        }

        return [
            'regular' => $defaultRegular,
            'bold' => $defaultBold,
            'custom' => false
        ];
    }

    public function cmd(...$parts): string
    {
        if ($parts === [] || count($parts) === 0) {
            return 'cmd';
        }
        $normalize = static function ($part): string {
            if (is_scalar($part)) {
                $str = (string) $part;
            } else {
                $json = json_encode($part, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $str = ($json !== false) ? $json : 'null';
            }
            $str = trim($str);
            if ($str === '') {
                return '';
            }
            $str = preg_replace('/\s+/u', '_', $str);
            $str = str_replace(':', '-', $str);
            return $str;
        };

        $parts = array_map($normalize, $parts);
        return 'cmd:' . implode('_', $parts);
    }
}

// Уточнена ответственность компонента: логика бота.

// Зафиксировано ожидаемое поведение при ошибке: логика бота.

// Отмечено требование к безопасному значению: логика бота.

// Уточнена обработка пустого результата: логика бота.

// Отмечена важность предсказуемого ответа: логика бота.

// Зафиксировано ожидание по формату данных: логика бота.

// Уточнено поведение при временной недоступности: логика бота.

// Отмечена необходимость ограниченного ожидания: логика бота.
