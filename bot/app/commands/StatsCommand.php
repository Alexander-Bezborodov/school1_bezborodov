<?php

class StatsCommand
{
    private const FIELD_KEY_USERNAME = 'username';

    private const FIELD_KEY_COUNT = 'count';

    private Bot $bot;

    public function __construct(Bot $bot)
    {
        $this->bot = $bot;
    }

    /**
     * @throws DateMalformedStringException
     */
    public function execute(array $message): void
    {
        $chatId = $message['chat']['id'];

        // Проверка прав администратора
        if (!$this->bot->isAdmin($chatId)) {
            $this->bot->sendMessage($chatId, "⛔ Недостаточно прав");
            return;
        }

        // Получаем общее количество пользователей
        $curCountRow = DB::selectOne("SELECT COUNT(*) AS count FROM users");
        $curCount = (int) $curCountRow[self::FIELD_KEY_COUNT];

        // Подсчет активных за последние 6 часов
        $active24hRow = DB::selectOne("
    SELECT COUNT(*) AS count 
    FROM users 
    WHERE chat_id != '1517511920' 
      AND action_date >= NOW() - INTERVAL 24 HOUR
");
        $active24hCount = (int) $active24hRow['count'];

        // Активные за последнюю неделю
        $activeWeekRow = DB::selectOne("
    SELECT COUNT(*) AS count 
    FROM users 
    WHERE chat_id != '1517511920' 
      AND action_date >= NOW() - INTERVAL 7 DAY
");
        $activeWeekCount = (int) $activeWeekRow['count'];

        // Получаем последнего активного пользователя (кроме исключенного ID)
        $lastUser = DB::selectOne("
        SELECT * 
        FROM users 
        WHERE chat_id != '1517511920' 
        ORDER BY action_date DESC 
        LIMIT 1
    ");

        if (!$lastUser) {
            $this->bot->sendMessage($chatId, "Нет данных о последней активности.");
            return;
        }

        // Часто используемые значения
        $userName = !empty($lastUser[self::FIELD_KEY_USERNAME]) ? "@" . $lastUser['username'] : $lastUser['chat_id'];
        $vipPrefix = $lastUser['vip'] == 1 ? "👑 " : "";
        $now = new DateTime();

        // Время последней активности
        $interval = (new DateTime($lastUser['action_date']))->diff($now);
        $minutesText = $interval->i === 0 ? "только что" : $interval->i . " мин. назад";
        $hoursText = $interval->h === 0 ? "" : $interval->h . "ч. ";

        // Количество пользователей с индикаторами (если есть предыдущий $users_count)
        $usersCount = isset($users_count) ? (int) $users_count : null;
        $usersText = $curCount;
        if ($usersCount !== null && $usersCount != $curCount) {
            $usersText .= " <s>($usersCount)</s> " . ($curCount > $usersCount ? "📈" : "📉");
        }

        // Формируем текст сообщения
        $textArr = [
            "Всего пользователей" => $usersText,
            "🔹 Активные за 24 часа" => $active24hCount,
            "🔹 Активные за неделю" => $activeWeekCount,
            "Последняя активность" => $hoursText . $minutesText,
            "Время обновления" => $now->format("H:i")
        ];

        // Информация о последнем пользователе
        if (!empty($lastUser["note"])) {
            $textArr["Опа"] = $vipPrefix . ($chatId == "1517511920" ? $lastUser["note"] : "") . " " . $userName;
        } else {
            $userArray = json_decode($lastUser["user_class"], true);
            if ($userArray) {
                $userText = $this->arrayToString($userArray) . $lastUser["class_litter"] . " " . $userName;
                $textArr[$lastUser["user_teacher"] == 1 ? "Учитель" : "Кто"] = $userText;
            } else {
                $textArr["Новый"] = $userName;
            }
        }

        // Формируем финальный текст
        $text = "";
        foreach ($textArr as $label => $value) {
            $text .= $label . ": <b>" . $value . "</b>\n";
        }

        $this->bot->sendMessage($chatId, $text);
    }

    private function arrayToString(array $items, $separator = "и", $trim_items = false): string
    {
        $items = array_filter($items, 'trim');
        if ($items == null) {
            return "пусто";
        }
        if ($trim_items) {
            $items = array_map("trim", $items);
        }
        $count = count($items);
        if ($count === 0) {
            return "";
        } elseif ($count === 1) {
            return $items[0];
        } elseif ($count === 2) {
            return implode(" {$separator} ", $items);
        } else {
            return implode(", ", array_slice($items, 0, -1)) . " {$separator} " . end($items);
        }
    }
}
