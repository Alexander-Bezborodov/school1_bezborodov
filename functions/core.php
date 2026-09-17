<?php
date_default_timezone_set('Europe/Moscow');

include_once __DIR__ . "/database/db.php";
include_once __DIR__ . "/types.php";
include_once __DIR__ . "/../utils/Auth.php";

include_once __DIR__ . "/ArrayToDatabase.php";
include_once __DIR__ . "/ArrayToImage.php";
include_once __DIR__ . "/ExcelToArray.php";
include_once __DIR__ . "/Logger.php";
include_once __DIR__ . "/Relay.php";

define('PROJECT_DIR', getenv('PROJECT_DIR') ?: dirname(__DIR__));

define('WEBHOOK_SECRET_TOKEN', getenv('WEBHOOK_SECRET_TOKEN') ?: '');
define('CRON_SECRET_TOKEN', getenv('CRON_SECRET_TOKEN') ?: '');
define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');
define('TELEGRAM_BOTLOG_TOKEN', getenv('TELEGRAM_BOTLOG_TOKEN') ?: '');

const IS_MAINTENANCE_MODE = false;
define('ADMIN_CHAT_ID', getenv('ADMIN_CHAT_ID') ?: '');

define('CLIENT_BOT_URL', getenv('CLIENT_BOT_URL') ?: 'https://t.me/school_shedule_bezborodov_bot');

function authTeacher(): ?string
{
    $name = $_SESSION['teacher'] ?? null;
    if ($name === null) {
        return null;
    }
    $formattedName =
        mb_strtoupper(mb_substr($name, 0, 1)) .
        mb_strtolower(mb_substr($name, 1));

    return $formattedName;
}

function esc(string $str): string
{
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function writeLog(string $message): void
{
    try {
        $line = date('[Y-m-d H:i:s] ') . $message . PHP_EOL;
        file_put_contents($_SERVER["DOCUMENT_ROOT"] . '/log.txt', $line, FILE_APPEND | LOCK_EX);
    } catch (Exception $e) {
    }
}

function writeLogObj($obj): void
{
    try {
        writeLog(json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    } catch (Exception $e) {
    }
}

function getDayDescription(string $dateString): string
{
    $date = new DateTime($dateString);
    $today = new DateTime();
    $tomorrow = new DateTime('tomorrow');

    $days = [
        1 => 'понедельник',
        2 => 'вторник',
        3 => 'среда',
        4 => 'четверг',
        5 => 'пятница',
        6 => 'суббота',
        7 => 'воскресенье',
    ];

    $dayOfWeek = $days[(int) $date->format('N')];

    if ($date->format('Y-m-d') === $today->format('Y-m-d')) {
        return "сегодня, $dayOfWeek";
    } elseif ($date->format('Y-m-d') === $tomorrow->format('Y-m-d')) {
        return "завтра, $dayOfWeek";
    } else {
        return $dayOfWeek;
    }
}

function getNextDayDate(): string
{
    $dateTime = new DateTime();

    $dayOfWeek = $dateTime->format('N');
    if ($dayOfWeek >= 5) {
        $nextDay = (new DateTime())->modify('next monday');
    } else {
        $nextDay = (new DateTime())->modify('+1 day');
    }
    return $nextDay->format('Y-m-d');
}

function generateTimetableByArray($timetable): string
{
    $body = '';

    foreach ($timetable as $lesson) {
        if (!empty($lesson[0])) {

            // Нормализуем время: 8:00 → 08:00
            $time = '';
            if (!empty($lesson['time'])) {
                $time = preg_replace_callback('/\b(\d):(\d{2})/', function ($m) {
                    return '0' . $m[1] . ':' . $m[2];
                }, $lesson['time']);
            }

            $lessonSubject = formatLessonSubject($lesson[0]);
            $subject = "<b>" . $lessonSubject . "</b>";
            $teacherOrRoom = trim(($lesson[1] ?? '') . ' ' . ($lesson[2] ?? ''));

            $body .= "$time $subject $teacherOrRoom\n";
        }
    }

    return $body;
}

function formatLessonSubject(string $lessonSubject): string
{
    if ($lessonSubject == "Физическая культура") {
        return "Физкультура";
    }
    if ($lessonSubject == "Вероятность и стат.") {
        return "Вероятность";
    }
    if ($lessonSubject == "Иностр. язык (англ)") {
        return "Английский";
    }
    return $lessonSubject;
}

function getFullWeekdayName(string $shortDay): string
{
    $daysMap = [
        'пн' => 'Понедельник',
        'вт' => 'Вторник',
        'ср' => 'Среда',
        'чт' => 'Четверг',
        'пт' => 'Пятница',
        'сб' => 'Суббота',
        'вс' => 'Воскресенье',
    ];
    $shortDay = ucfirst(mb_strtolower(trim($shortDay)));
    return $daysMap[$shortDay] ?? 'Неизвестный день';
}

function getShortWeekdayByDate(?string $date = null): string
{
    $timestamp = $date ? strtotime($date) : time();
    $dayNum = (int) date('N', $timestamp);
    $days = [
        1 => 'Пн',
        2 => 'Вт',
        3 => 'Ср',
        4 => 'Чт',
        5 => 'Пт',
        6 => 'Сб',
        7 => 'Вс',
    ];
    return $days[$dayNum];
}

function getShortWeekdays(): array
{
    return ['Пн', 'Вт', 'Ср', 'Чт', 'Пт'];
}

function getTodayShortWeekday(): string
{
    $days = [
        1 => 'Пн',
        2 => 'Вт',
        3 => 'Ср',
        4 => 'Чт',
        5 => 'Пт',
        6 => 'Сб',
        7 => 'Вс',
    ];
    $dayNum = (int) date('N');
    if ($dayNum >= 5) {
        return 'Пн';
    }
    return $days[$dayNum];
}

function getWeekdayNumber(string $when = 'today'): int
{
    if ($when === 'tomorrow') {
        $timestamp = strtotime('+1 day');
    } else {
        $timestamp = time();
    }
    $day = (int) date('N', $timestamp);
    return min($day - 1, 5);
}

function getScheduleEmoji($getDefaultPack = true): string
{
    $date = date('m-d');

    // Список праздников
    $holidayEmojis = [
        '01-01' => ['🎄', '🎉', '🍾', '🥂', '❄️', '❄️', '⛄', '🎿'], // Новый год
        '01-07' => ['🎄', '✨', '🕊️'],           // Рождество (православное)
        '02-14' => ['❤️', '💌', '💞', '💘', '🌹'], // День Валентина
        '02-23' => ['🪖', '💪'],            // 23 февраля
        '03-08' => ['🌷', '💐', '🌸', '👩‍🦰', '💖'],
        '04-01' => ['🤡', '😜', '🎭'],
        '05-01' => ['🌿', '🌞', '👷‍♂️', '🧑‍🌾'],
        '05-09' => ['🎖️', '🌺', '🕊️'],
        '06-01' => ['🧸', '🎈', '👶'],
        '09-01' => ['📚', '✏️', '🎒', '🏫'],
        '10-31' => ['🎃', '👻', '🕸️', '🦇'],
        '12-24' => ['🎅', '🎁', '🎄', '✨'],
        '12-31' => ['🎆', '🎊', '🍾', '🥂'],
    ];

    if (!$getDefaultPack) {
        $holidayEmojis = [
            '01-01' => ['🎄', '🍾', '🥂', '❄️', '❄️'], // Новый год
            '01-07' => ['🎄', '✨'],           // Рождество (православное)
            '02-14' => ['❤️', '💞', '💘', '🌹'], // День Валентина
            '02-23' => ['🪖'],            // 23 февраля
            '03-08' => ['🌷', '💐', '🌸'],
            '04-01' => ['🎭'],
            '05-01' => ['🌿', '🌞'],
            '05-09' => ['🎖️', '🌺', '🕊️'],
            '06-01' => ['🧸', '🎈'],
            '09-01' => ['✏️', '🎒'],
            '10-31' => ['🎃', '🕸️', '🦇'],
            '12-24' => ['🎁', '🎄', '✨'],
            '12-31' => ['🎊', '🍾', '🥂'],
        ];
    }

    // Диапазоны праздничных тем
    // Формат: 'ключ_праздника' => ['start' => 'mm-dd', 'end' => 'mm-dd']
    $holidayRanges = [
        // Новый год с 10 декабря по 1 января
        '01-01' => ['start' => '12-10', 'end' => '01-01'],

        // Валентин — за 3 дня: с 11 по 14 февраля
        '02-14' => ['start' => '02-12', 'end' => '02-14'],
    ];

    $defaultEmojis = ['✍️', '✍️', '📝', '📌', '✏️', '📂'];
    if (!$getDefaultPack) {
        $defaultEmojis = [''];
    }

    // Проверяем диапазоны
    foreach ($holidayRanges as $holidayDate => $range) {
        if (isDateInRange($date, $range['start'], $range['end'])) {
            return getRandomEmoji($holidayEmojis[$holidayDate]);
        }
    }

    // Остальные — только в конкретный день
    if (array_key_exists($date, $holidayEmojis)) {
        return getRandomEmoji($holidayEmojis[$date]);
    }

    return getRandomEmoji($defaultEmojis);
}

function getRandomEmoji(array $arr): string
{
    return $arr[array_rand($arr)];
}

// Проверка диапазона дат без года
function isDateInRange(string $current, string $start, string $end): bool
{
    // Для диапазонов "через Новый год" типа 12-10 → 01-01
    if ($start > $end) {
        return ($current >= $start || $current <= $end);
    }
    return ($current >= $start && $current <= $end);
}

// Уточнена ответственность компонента: основные функции.

// Отмечена граница входных данных: основные функции.
