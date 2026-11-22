<?php
require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";

class GetTeacherTimetableCommand
{
    private const FIELD_KEY_FILE = 'file';

    private Bot $bot;

    public function __construct(Bot $bot)
    {
        $this->bot = $bot;
    }

    /**
     * @throws Exception
     */
    public function execute(array $user, array $message, string $teacherName): void
    {
        $chatId = $message['chat']['id'];
        $format = $user["format"] ?? 'TEXT';

        $today = date('Y-m-d', strtotime('+1 day'));
        $tomorrow = date('Y-m-d');

        if (!$this->bot->isAdmin($chatId)) {
            return;
        }

        try {
            $timetable = getLessonsByDate($today, $teacherName);
        } catch (Exception $e) {
            $this->bot->sendMessage($chatId, $e->getMessage());
            return;
        }

        $this->bot->sendMessage(ADMIN_CHAT_ID, "🔹 Пользователь <code>$chatId</code> подсматривает за учителем <b>$teacherName</b>");

        $dateUsed = $today;

        if (empty($timetable)) {
            try {
                $timetable = getLessonsByDate($tomorrow, $teacherName);
                $dateUsed = $tomorrow;
            } catch (Exception $e) {
                $this->bot->sendMessage($chatId, $e->getMessage());
                return;
            }
        }

        if (empty($timetable)) {
            $this->bot->sendMessage($chatId, "❌ Такого учителя нет");
            return;
        }

        $header = "👁️ <b>$teacherName</b>, " . getDayDescription($dateUsed) . "\n\n";

        // Формат IMAGE
        if ($format === 'IMAGE') {
            $font = $this->bot->getFontByUser($user);
            $data = arrayToImage($teacherName, $timetable, $font['regular'], $font['bold']);

            if (isset($data['error'])) {
                $this->bot->sendMessage($chatId, $data['error']);
                return;
            }

            if (!isset($data[self::FIELD_KEY_FILE])) {
                $this->bot->sendMessage($chatId, "⛔ Нет данных для изображения");
                return;
            }

            $this->bot->sendPhotoByFilePath($chatId, $data['file'], $header);
            return;
        }

        $text = $header . generateTimetableByArray($timetable);
        $this->bot->sendMessage($chatId, $text);
    }
}
