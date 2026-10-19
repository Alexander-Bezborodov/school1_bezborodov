<?php

class SetClassCommand
{
    private const FIELD_KEY_PARALLELS = 'parallels';

    private const FIELD_KEY_CLASSES = 'classes';

    private const FIELD_KEY_USERNAME = 'username';

    private const FIELD_KEY_CHAT = 'chat';

    private Bot $bot;

    public function __construct(Bot $bot)
    {
        $this->bot = $bot;
    }

    /**
     * @throws DateMalformedStringException
     */
    public function execute($user, array $message): void
    {
        $chatId = $message[self::FIELD_KEY_CHAT]['id'];

        $chatUsername = $message['chat'][self::FIELD_KEY_USERNAME] ?? '';
        $isGroup = $chatId < 0;
        $groupMembers = 0;

        if ($isGroup) {
            $chatInfo = $this->bot->getChat($chatId);
            $groupMembers = $chatInfo['result']['members_count'] ?? 0;
        }

        // --- Парсинг классов и параллелей ---
        $result = $this->parseClassesAndParallels($message['text'], $hiddenClasses);

        if (empty($result[self::FIELD_KEY_CLASSES]) && empty($result[self::FIELD_KEY_PARALLELS]) && empty($hiddenClasses)) {
            $this->bot->sendMessage($chatId, "❌ Неверный формат классов или параллели");
            (new HelpCommand($this->bot))->execute($message);
            return;
        }

        $allClasses = $result['classes'] ?? [];
        $availableClasses = getLastListOfClasses();

        foreach ($allClasses as $class) {
            if (!in_array($class, $availableClasses)) {
                $this->bot->sendMessage($chatId, "❌ Класса «" . $class . "» не существует");
                die();
            }
        }

        if (!empty($result['parallels'])) {
            foreach ($result['parallels'] as $parallel) {
                $num = (int) $parallel;
                $pattern = ($num >= 1 && $num <= 9) ? '/^' . $num . '[^0-9]/u' : '/^' . $num . '/u';

                $matchedClasses = array_values(array_filter($availableClasses, function ($item) use ($pattern) {
                    return preg_match($pattern, $item);
                }));

                $allClasses = array_merge($allClasses, $matchedClasses);
            }
        }

        $allClasses = array_values(array_unique($allClasses));
        $classJson = json_encode(array_values($allClasses), JSON_UNESCAPED_UNICODE);

        $user = DB::selectOne("SELECT * FROM users WHERE chat_id = ? LIMIT 1", [$chatId]);
        if ($user == null) {
            DB::insert(
                "INSERT INTO users (chat_id, username, group_members_count)
             VALUES (:chat_id, :username, :group_members_count)",
                [
                    'chat_id' => $chatId,
                    'username' => $chatUsername,
                    'group_members_count' => $groupMembers
                ]
            );

            $this->bot->alertAdmin("Новый пользователь (3.0): " . ($chatUsername ? "@{$chatUsername} " : "") . "[$chatId]");
        }

        if (empty($hiddenClasses)) {
            $sql = "UPDATE users 
                SET class_list = :class_list" .
                (count($result['classes']) === 1 ? ", class_start = COALESCE(class_start, :class_start)" : "") . "
                WHERE chat_id = :chat_id";

            $params = ['class_list' => $classJson, 'chat_id' => $chatId];
            if (count($result['classes']) === 1)
                $params['class_start'] = $result['classes'][0];

            DB::execute($sql, $params);
        }

        // --- Сообщение пользователю ---
        $this->bot->sendMessage($chatId, $this->formatSelectionMessage($result), $this->bot->getMenuKeyboard($chatId));

        // --- Скрытый просмотр ---
//        if (!empty($hiddenClasses)) {
//            $this->bot->sendMessage(
//                $chatId,
//                "👁️ <b>Просмотр</b> «" . implode("», «", $hiddenClasses) . "» класса",
//                $this->bot->getMenuKeyboard($chatId)
//            );
//            (new GetTimetableCommand($this->bot))->execute($user, $message, $date, $hiddenClasses);
//            return;
//        }

        $date = new DateTime();
        if ((int) $date->format('H') >= 18) {
            $date->modify('+1 day');
        }
        $date = $date->format('Y-m-d');

        $user = DB::selectOne("
            SELECT u.*, t.name AS teacher_name
            FROM users u
            LEFT JOIN users__teachers t ON t.chat_id = u.chat_id
            WHERE u.chat_id = ?
            LIMIT 1
        ", [$chatId]);

        (new GetTimetableCommand($this->bot))->execute($user, $message, $date);
    }

    private function formatSelectionMessage(array $result): string
    {
        $messageText = '';

        // Параллели
        if (!empty($result['parallels'])) {
            sort($result['parallels'], SORT_NUMERIC);
            $messageText .= "✅ Ты выбрал «" . $this->formatList($result['parallels']) . "» параллель";
        }

        // Классы
        if (!empty($result['classes'])) {
            usort($result['classes'], function ($a, $b) {
                preg_match('/^(\d+)([А-Я])$/u', $a, $ma);
                preg_match('/^(\d+)([А-Я])$/u', $b, $mb);
                $numA = (int) $ma[1];
                $charA = $ma[2];
                $numB = (int) $mb[1];
                $charB = $mb[2];
                return $numA === $numB ? strcmp($charA, $charB) : $numA - $numB;
            });

            if ($messageText)
                $messageText .= " и ";
            else
                $messageText .= "✅ Ты выбрал ";

            if (count($result['classes']) === 1) {
                $messageText .= "«" . $result['classes'][0] . "» класс";
            } else {
                $messageText .= "«" . $this->formatList($result['classes']) . "» классы";
            }
        }

        return $messageText;
    }

    private function formatList(array $items): string
    {
        $count = count($items);
        if ($count === 0)
            return '';
        if ($count === 1)
            return $items[0];
        $last = array_pop($items);
        return implode(", ", $items) . " и " . $last;
    }

    private function parseClassesAndParallels(string $text, ?array &$hiddenClasses = []): array
    {
        $hiddenClasses = [];

        $text = preg_replace('/\s*,\s*/u', ',', $text);
        $parts = preg_split('/[\s,]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);

        $replace = [
            'A' => 'А',
            'B' => 'В',
            'E' => 'Е',
            'K' => 'К',
            'M' => 'М',
            'H' => 'Н',
            'O' => 'О',
            'P' => 'Р',
            'C' => 'С',
            'T' => 'Т',
            'Y' => 'У',
            'X' => 'Х',
            'a' => 'А',
            'b' => 'В',
            'e' => 'Е',
            'k' => 'К',
            'm' => 'М',
            'h' => 'Н',
            'o' => 'О',
            'p' => 'Р',
            'c' => 'С',
            't' => 'Т',
            'y' => 'У',
            'x' => 'Х'
        ];

        $classes = [];
        $parallels = [];

        foreach ($parts as $part) {
            $isHidden = false;
            $part = trim($part);
            if (mb_substr($part, 0, 1) === '-') {
                $isHidden = true;
                $part = mb_substr($part, 1);
            }

            $part = preg_replace_callback('/[A-Za-z]/u', fn($m) => $replace[$m[0]] ?? $m[0], $part);
            $part = mb_strtoupper(trim($part));

            if (preg_match('/^(?:[1-9]|1[0-1])[А-Я]$/u', $part)) {
                if ($isHidden)
                    $hiddenClasses[] = $part;
                else
                    $classes[] = $part;
            } elseif (preg_match('/^(?:[1-9]|1[0-1])$/u', $part)) {
                if (!$isHidden)
                    $parallels[] = $part;
            } else {
                return []; // неверный формат
            }
        }

        // Убираем дубликаты
        $classes = array_unique($classes);
        $parallels = array_unique($parallels);
        $hiddenClasses = array_unique($hiddenClasses);

        // Если цифра обычного класса совпадает с параллелью, переносим в параллель
        foreach ($classes as $key => $class) {
            $num = (int) $class;
            if (in_array((string) $num, $parallels)) {
                unset($classes[$key]);
            }
        }

        return [
            'classes' => array_values($classes),
            'parallels' => array_values($parallels)
        ];
    }
}
