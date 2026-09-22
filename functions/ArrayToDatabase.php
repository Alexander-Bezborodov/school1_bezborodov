<?php
require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";

function databaseCreateTables(): void {
    try {
        DB::select('SELECT id FROM timetable WHERE 1=1');
    } catch (Exception $e) {
        DB::execute("
            CREATE TABLE IF NOT EXISTS `timetable` (
            `id` varchar(100) NOT NULL, -- Номер класса
            `date` date NOT NULL,
            `time` varchar(50) NOT NULL,
            `number` varchar(25) NOT NULL,
            `sort` TINYINT(2) UNSIGNED NOT NULL, -- колонка number через intval()
            `lesson` varchar(100) NOT NULL,
            `teacher_or_class` varchar(100) NOT NULL,
            `cabinet` varchar(25) NOT NULL,
            PRIMARY KEY (`id`,`date`,`number`)
            ) ENGINE=InnoDB;
        ");
    }

    try {
        DB::select('SELECT id FROM `timetable__common` WHERE 1=1');
    } catch (Exception $e) {
        DB::execute("
            CREATE TABLE IF NOT EXISTS `timetable__common` (
            `id` varchar(100) NOT NULL, -- Номер класса
            `day_of_week` varchar(10) NOT NULL,
            `time` varchar(50) NOT NULL,
            `number` varchar(25) NOT NULL,
            `sort` TINYINT(2) UNSIGNED NOT NULL, -- колонка number через intval()
            `lesson` varchar(100) NOT NULL,
            `teacher_or_class` varchar(100) NOT NULL,
            `cabinet` varchar(25) NOT NULL,
            PRIMARY KEY (`id`,`day_of_week`,`number`)
            ) ENGINE=InnoDB;
        ");
    }
}

function clearOldTimeTables(): bool {
    // В ежедневном расписании чистим только старые
    try {
        DB::execute('DELETE FROM `timetable` WHERE `date`<CURDATE()-INTERVAL 180 DAY');
    } catch (Exception $e) {
        return false;
    }

    return true;
}

function deleteOneDayByDate(string $date): bool {
    try {
        $count = DB::selectOne('SELECT COUNT(*) as Count FROM `timetable` WHERE `date`=:date', ['date' => $date])['Count'];
    } catch (Exception $e) {
        return false;
    }

    if ($count < 1)
        return false;

    try {
        DB::execute('DELETE FROM `timetable` WHERE `date`=:date', ['date' => $date]);
    } catch (Exception $e) {
        return false;
    }

    return true;
}

function getSQLDateFromTextDayOfWeek(string $dow): string {
    $weekdays = [
        'ПН' => 'Monday',
        'ВТ' => 'Tuesday',
        'СР' => 'Wednesday',
        'ЧТ' => 'Thursday',
        'ПТ' => 'Friday',
        'СБ' => 'Saturday',
        'ВС' => 'Sunday',
    ];

    if (!array_key_exists($dow, $weekdays))
        return '';
    return date('Y-m-d', strtotime('next ' . $weekdays[$dow]));
}

// Принимает на вход массив от вызова функции excelToArray:
// [
//     "НОМЕР_КЛАССА/ФАМИЛИЯ_УЧИТЕЛЯ" => Array(
//         "ПН"=> Array(
//             "1 урок" => ["num", "time", "0", "1", "2"],
//             "2 урок" => ["num", "time", "0", "1", "2"],
//             ...
//         ),
//         "ВТ"=> Array(
//             "1 урок" => ["num", "time", "0", "1", "2"],
//             "2 урок" => ["num", "time", "0", "1", "2"],
//             ...
//         ),
//         ...
//     )
// ]
//
// По умолчанию в $inputData присутствуют все дни недели из excel файла
// Но надо сохранить только некоторые дни:
Define('DAY_OF_WEEK_TODAY', ['ПН', 'ВТ', 'СР', 'ЧТ', 'ПТ', 'СБ', 'ВС']);
Define('DAY_OF_WEEK_LOAD', ['ВТ', 'СР', 'ЧТ', 'ПТ', '', '', 'ПН']);
// Если сегодня:
// ПН - загружаем расписание на ВТ
// ВТ - загружаем расписание на СР
// СР - загружаем расписание на ЧТ
// ЧТ - загружаем расписание на ПТ
// ПТ - возвращаем ошибку
// СБ - возвращаем ошибку
// ВС - загружаем расписание на ПН

// Ответ функции в строке, если строка пустая - значит всё OK!

function clearCaches(): void {
    $dir = $_SERVER["DOCUMENT_ROOT"] . '/caches';
    $days = 14; // сколько дней считать "старыми"
    $limit = time() - ($days * 24 * 60 * 60); // секунды за 14 дней

    if (is_dir($dir)) {
        foreach (glob($dir . '/*') as $file) {
            if (is_file($file)) {
                if (filemtime($file) < $limit) {
                    unlink($file);
                }
            }
        }
    }
}

function initTables(): string {
    try {
        databaseCreateTables();
    } catch (Exception $e) {
        return 'Ошибка создания таблиц в базе данных';
    }

    try {
        clearOldTimeTables();
    } catch (Exception $e) {
        return 'Ошибка очистки таблиц в базе данных';
    }

    try {
        clearCaches();
    } catch (Exception $e) {
        return 'Ошибка очистки кеша';
    }

    return '';
}

function arrayToDatabase(array $inputData): string {
    initTables();

    $day_today = DAY_OF_WEEK_TODAY[intval(date('N')) - 1];
    $day_in_array = DAY_OF_WEEK_LOAD[intval(date('N')) - 1];
    if ($day_in_array === '')
        return "Сегодня {$day_today}, загрузка расписания в этот день на завтра не предусмотрена";

    $date_from_name = getSQLDateFromTextDayOfWeek($day_in_array);
    if (!strlen($date_from_name))
        return 'В DAY_OF_WEEK_LOAD указан неверный день недели';

    $items = [];

    foreach ($inputData as $teacher_or_class_key => $dow)
        foreach ($dow as $day_of_week_key => $lessons)
            if ($day_of_week_key == $day_in_array)
                foreach ($lessons as $number => $lesson)
                    $items[] = [
                        'id' => $teacher_or_class_key,
                        'date' => $date_from_name,
                        'time' => $lesson['time'],
                        'number' => $number,
                        'sort' => $lesson['sort'],
                        'lesson' => $lesson['0'],
                        'teacher_or_class' => $lesson['1'],
                        'cabinet' => $lesson['2'] ?? ''
                    ];

    if (!count($items))
        return "В файле нет {$day_in_array}, загрузить не удалось";

    try {
        DB::begin();
        DB::insert('INSERT INTO timetable (`id`, `date`, `time`, `number`, `sort`, `lesson`, `cabinet`, `teacher_or_class`) 
            VALUES (:id, :date, :time, :number, :sort, :lesson, :cabinet, :teacher_or_class)', $items);
        DB::commit();
    } catch (Exception $e) {
        DB::rollback();
        $errText = $e->getMessage();
        if (mb_strpos(mb_strtoupper($errText), 'PRIMARY') !== false)
            return "Ошибка, расписание на дату {$date_from_name} уже есть";
        else
            return "Ошибка, возможно расписание на дату {$date_from_name} уже есть или ошибки в колонках: {$errText}";
    }

    return '';
}

function commonTimeTablesToDatabase(array $inputData): string {
    initTables();

    $items = [];

    foreach ($inputData as $teacher_or_class_key => $dow)
        foreach ($dow as $day_of_week_key => $lessons)
            foreach ($lessons as $number => $lesson)
                $items[] = [
                    'id' => $teacher_or_class_key,
                    'day_of_week' => $day_of_week_key,
                    'time' => $lesson['time'],
                    'number' => $number,
                    'sort' => $lesson['sort'],
                    'lesson' => $lesson['0'],
                    'teacher_or_class' => $lesson['1'],
                    'cabinet' => $lesson['2'] ?? ''
                ];

    try {
        DB::begin();

        // В постоянном расписании чистим полностью
        DB::execute('DELETE FROM `timetable__common`');

        DB::insert('INSERT INTO `timetable__common` (`id`, `day_of_week`, `time`, `number`, `sort`, `lesson`, `cabinet`, `teacher_or_class`) 
            VALUES (:id, :day_of_week, :time, :number, :sort, :lesson, :cabinet, :teacher_or_class)', $items);
        DB::commit();
    } catch (Exception $e) {
        DB::rollback();
        //echo $e->getMessage();
        return "Ошибка добавления постоянного расписания в базу данных: " . $e->getMessage();
    }

    return '';
}

// Пустой массив возвращается только, если таблица не существует, либо расписания никогда не загружались,
// Либо, если последний раз расписание загружали более 180 дней назад и чистка всё старьё удалила
// Возврат: список ['1A','1Б',....'11Г']
function getLastListOfIds(): array {
    try {
        $result = DB::select('SELECT DISTINCT `id` FROM `timetable` WHERE `date`>CURDATE()-INTERVAL 30 DAY GROUP BY `id`');
    } catch (Exception $e) {
        return [];
    }

    $ids = [];
    foreach ($result as $row)
        $ids[] = $row['id'];

    return $ids;
}

// Возвращает актуальный список классов
function getLastListOfClasses(): array {
    $ids = getLastListOfIds();

    $result = [];
    foreach ($ids as $row)
        if (intval($row) > 0)
            $result[] = $row;

    usort($result, function ($a, $b) {
        preg_match('/(\d+)(.+)/u', $a, $a_parts);
        preg_match('/(\d+)(.+)/u', $b, $b_parts);

        $numA = (int)$a_parts[1];
        $numB = (int)$b_parts[1];
        $letA = $a_parts[2];
        $letB = $b_parts[2];

        if ($numA === $numB)
            return strcmp($letA, $letB);
        return $numA <=> $numB;
    });

    return $result;
}

// Возвращает актуальный список учителей
function getLastListOfTeachers(): array {
    $ids = getLastListOfIds();

    $result = [];
    foreach ($ids as $row)
        if (intval($row) == 0)
            $result[] = $row;

    sort($result, SORT_STRING | SORT_FLAG_CASE);
    return $result;
}

function getLessonsByDate(string $date, string $id): array {
    try {
        $rows = DB::select('SELECT `number`,`time`,`lesson`,`teacher_or_class`,`cabinet` FROM `timetable`
            WHERE `date`=:date AND `id`=:id ORDER BY `sort`', ['date' => $date, 'id' => $id]);
    } catch (Exception $e) {
        return [];
    }

    $result = [];

    foreach ($rows as $row)
        $result[$row['number']] = ['num' => $row['number'], 'time' => $row['time'], '0' => $row['lesson'], '1' => $row['teacher_or_class'], '2' => $row['cabinet']];

    return $result;
}

function getLessonsByDateFromCommonTtimeTable(string $day_of_week, string $id): array {
    try {
        $rows = DB::select('SELECT `number`,`time`,`lesson`,`teacher_or_class`,`cabinet` FROM `timetable__common`
            WHERE `day_of_week`=:day_of_week AND `id`=:id ORDER BY `sort`', ['day_of_week' => $day_of_week, 'id' => $id]);
    } catch (Exception $e) {
        return [];
    }

    $result = [];

    foreach ($rows as $row)
        $result[$row['number']] = ['num' => $row['number'], 'time' => $row['time'], '0' => $row['lesson'], '1' => $row['teacher_or_class'], '2' => $row['cabinet']];

    return $result;
}

// Уточнена ответственность компонента: загрузка данных в базу.

// Отмечена граница входных данных: загрузка данных в базу.

// Зафиксировано ожидаемое поведение при ошибке: загрузка данных в базу.

// Уточнена обработка пустого результата: загрузка данных в базу.
