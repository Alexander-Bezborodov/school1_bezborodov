<?php
require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";

class GetTimetableCommand
{
    private const FIELD_KEY_FILE = 'file';

    private const FIELD_KEY_ERROR = 'error';

    private Bot $bot;

    public function __construct(Bot $bot)
    {
        $this->bot = $bot;
    }

    /**
     * @throws Exception
     */
    public function execute(array $user, array $message, string $date, string $class = null, int $messageId = null, bool $selection = false, bool $cron = false): void
    {
        $chatId = $message['chat']['id'];
        $classList = $user["class_list"] ?? null;
        $format = $user["format"] ?? 'TEXT';

        $classes = [];

        if (is_string($classList)) {
            $decoded = json_decode($classList, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $classes = $decoded;
            } else {
                $classes = [$classList];
            }
        } elseif (is_array($classList)) {
            $classes = $classList;
        }

        if ($user["teacher_name"] !== null) {
            $teacherName = $user["teacher_name"];
            $classes[] = mb_strtoupper(mb_substr($teacherName, 0, 1)) . mb_strtolower(mb_substr($teacherName, 1));
        }

        $classActive = $user["class_active"];
        $classActive = $class ?? ($classActive ?? $classes[0]);

        if (!in_array($classActive, $classes) && count($classes) > 0) {
            $classActive = $classes[0];
        }

        if ($user["teacher_name"] !== null && ($cron || $user["hide_classes"] == 1)) {
            $classActive = $user["teacher_name"];
        }

        // Формируем клавиатуру, если больше одного класса
        $keyboard = null;
        if (count($classes) > 1 && !($user["teacher_name"] !== null && $user["hide_classes"] == 1)) {
            $keyboard = ['inline_keyboard' => []];
            $row = [];
            $e = "🔹";
            $activeBtnBackground = "primary";
            if (md5($classActive) === "c74ddfb2b2ac898acbca91941c9998a0" && $cron) {
                $e = "💅";
                $activeBtnBackground = "danger";
            }
            if ($chatId == "5020809247" || $chatId == "1517511920") {
                $e = "😈";
                $activeBtnBackground = "danger";
            }
            foreach ($classes as $c) {
                $label = ($classActive === $c) ? "$e $c" : $c;
                $row[] = Keyboard::button($label, "cmd:timetable_" . $c . "_" . $date, $classActive === $c ? $activeBtnBackground : null);
                if (count($row) === 3) {
                    $keyboard['inline_keyboard'][] = $row;
                    $row = [];
                }
            }
            if (count($row) > 0) {
                $keyboard['inline_keyboard'][] = $row;
            }
        }

        // Получаем расписание
        try {
            $timetable = getLessonsByDate($date, $classActive);
        } catch (Exception $e) {
            if ($this->bot->isAdmin($chatId)) {
                $this->bot->sendMessage($chatId, $e->getMessage());
            } else {
                $this->bot->sendMessage($chatId, "☑️ Не удалось загрузить расписание, попробуйте чуть позже");
            }
            return;
        }

        if (mb_strlen($classActive) > 3) {
            $classActive = mb_strtolower($classActive, 'UTF-8');
            $classActive = mb_strtoupper(mb_substr($classActive, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($classActive, 1, null, 'UTF-8');
            // writeLog($classActive);
        } else {
            DB::execute("UPDATE users SET class_active=? WHERE chat_id = ?", [$classActive, $chatId]);
            // writeLog('UPDATE users SET class_active');
        }

        $checksum = md5(json_encode($timetable, JSON_UNESCAPED_UNICODE));

        // SELECT DISTINCT `graduate_year` FROM `users`; // 0000, NULL, 2025

        // SELECT COUNT(*) FROM `users`;  // 2847 строк
        // SELECT COUNT(*) FROM `users` WHERE `graduate_year`='0000'; // 2740 строк
        // SELECT COUNT(*) FROM `users` WHERE `graduate_year` IS NULL; // 22 строки
        // SELECT COUNT(*) FROM `users` WHERE `graduate_year`='2025'; // 85 строк

        if (($checksum == $user['timetable_control_sum'] || $user['graduate_year'] !== null) && $selection)
            return;

        //writeLog('chatId = ' . $chatId);

        DB::execute("UPDATE users SET timetable_control_sum=? WHERE chat_id = ?", [$checksum, $chatId]);

        $prefixEmoji = getScheduleEmoji();
        if (md5($classActive) === "c74ddfb2b2ac898acbca91941c9998a0") {
            $prefixEmoji = "💅";
        }

        $header = "$prefixEmoji <a href='https://timetable.na4u.ru/?share=" . $classActive . "'><b>$classActive,</b></a> " . getDayDescription($date) . "\n\n";

        if ($selection) {
            $header = "Расписание для <a href='https://timetable.na4u.ru/?share=" . $classActive . "'><b>$classActive</b></a> на " . getDayDescription($date) . "\n\n";
            if ($user["vip"] == 1) {
                $header = "👑 $header";
            }
        }

        if (!in_array($class, $classes) && $class != null) {
            $header = "<code>Классы обновлены</code>\n" . $header;
        }

        //if (time() < strtotime('2025-11-30')) $format = "TEXT";

        if (count($timetable) > 0) {
            if ($format === 'IMAGE') {
                $font = $this->bot->getFontByUser($user);
                $data = arrayToImage($classActive, $timetable, $font['regular'], $font['bold']);
                if (isset($data[self::FIELD_KEY_ERROR])) {
                    $this->bot->sendMessage($chatId, $data['error']);
                    return;
                }
                if (!isset($data[self::FIELD_KEY_FILE])) {
                    $this->bot->sendMessage($chatId, "Нет данных для изображения");
                    return;
                }

                if ($messageId) {
                    $this->bot->editPhotoAndCaption($chatId, $messageId, $data['file'], $header, $keyboard);
                } else {
                    $this->bot->sendPhotoByFilePath($chatId, $data['file'], $header, $keyboard);
                }
            } else {
                $text = $header . generateTimetableByArray($timetable);
                if ($messageId) {
                    $this->bot->editMessage($chatId, $messageId, $text, $keyboard);
                } else {
                    $this->bot->sendMessage($chatId, $text, $keyboard);
                }
            }
        } else if (!$cron) {
            $text = "⌚ Расписание для «" . $classActive . "» еще не было загружено";

            $hour = (int) date('H') + 2;
            $dayOfWeek = (int) date('N');

            if ($hour >= 20 && $dayOfWeek !== 5 && $dayOfWeek !== 6) {
                $text .= "\n\n<b>ℹ️ Если расписание на завтра ещё не загружено, уточняйте у классного руководителя или ориентируетесь на постоянное расписание</b>";
            }

            $keyboard['inline_keyboard'][] = [
                Keyboard::button("Постоянное", $this->bot->cmd("common-timetable", $classActive, getShortWeekdayByDate($date))),
                Keyboard::button("Обновить", $this->bot->cmd("timetable", $classActive, $date))
            ];

            if ($messageId) {
                $editStatus = $this->bot->editMessage($chatId, $messageId, $text, $keyboard);
                if (!$editStatus) {
                    $this->bot->deleteMessage($chatId, $messageId);
                    $this->bot->sendMessage($chatId, $text, $keyboard);
                }
            } else {
                $this->bot->sendMessage($chatId, $text, $keyboard);
            }
        }
    }
}
