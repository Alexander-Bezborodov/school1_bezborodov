<?php
//https://github.com/shuchkin/simplexlsx
require_once __DIR__ . "/SimpleXLSX.php";

use Shuchkin\SimpleXLSX;

// Поддержку XLS убрал, т.к. в нём ограничение на 256 колонок
// Расписание обрезано (php не причём, проблема именно в Excel)

// Возвращает массив:
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

function excelToArray(string $filename): array {
    $classes = []; // само расписание

    try {

        $cols_count = $rows_count = 0;

        // Файл есть? Он XLSX?
        if (!file_exists($filename))
            return ['error' => 'Файл не существует'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if ($ext !== 'xlsx')
            return ['error' => 'Файл должен быть в формате XLSX'];

        if ($xlsx = SimpleXLSX::parse($filename)) {
            $rows = [];

            $dim = $xlsx->dimension();
            $cols_count = $dim[0];
            $rows_count = $dim[1];

            foreach ($xlsx->readRows() as $k => $r) {
                $col = [];
                for ($i = 0; $i < $cols_count; $i++)
                    $col[$i] = $r[$i] ?? false;
                $rows[] = $col;
            }
        } else
            return ['error' => 'Не удалось получить содержимое файла'];

        if ($cols_count < 100 || $rows_count < 50)
            return ['error' => 'Таблица с данными слишком маленькая'];

        // cols_count может быть больше таблицы
        for ($col = $cols_count; $col >= 0; $col--)
            for ($row = 0; $row < $rows_count; $row++)
                if (isset($rows[$row][$col]) && mb_strlen(trim($rows[$row][$col]))) {
                    $cols_count = $col;
                    break 2;
                }

        // Колонки и строки без которых нельзя
        $col_day_of_week = 0;
        $col_number_of_lesson = 1;
        $col_time_of_lesson = 2;

        $rows_of_class_numbers = 0;
        $rows_of_class_name = 1;

        // Настройки
        $cols_on_class = 3; // сколько колонок на один класс

        // По умолчанию расписание начинается с 3й колонки,
        // но если кто-то вставит 4ю, то ничего не сломается,
        // т.к. ниже мы это проверяем
        $first_data_col = 3;
        $first_data_row = 3;

        for ($row = 0; $row < $first_data_row; $row++)
            for ($col = 0; $col < $first_data_col; $col++)
                if (mb_strlen(trim($rows[$row][$col])))
                    return ['error' => "Первые $first_data_col x $first_data_row ячейки должны быть пустыми, пропущена строка"];

        // Вдруг у какого-то класса или учителя колонок <> $cols_on_class
        $class_name_row = $rows_of_class_name;
        for ($startCol = $first_data_col; $startCol < $cols_count - 1; $startCol++)
            if (mb_strlen(trim($rows[$class_name_row][$startCol])))
                break;

        for ($col = $startCol; $col < $cols_count - 1; $col += $cols_on_class)
            if (!mb_strlen(trim($rows[$class_name_row][$col])))
                return ['error' => 'В загружаемом файле есть классы/учителя не с тремя колонками'];

        $day_of_week = $number_of_lesson = $time_of_lesson = '';

        $num_of_classes = [];

        // Отладка
        for ($row = 0; $row < $rows_count; $row++) {
            // Значение обязательных колонок, чтобы дублировать в каждый класс

            for ($col = 0; $col < $cols_count; $col++) {
                $item = trim($rows[$row][$col]);

                if ($row == $rows_of_class_numbers && $item == '1')
                    $first_data_col = $col;

                if ($row >= $first_data_row && $col < $first_data_col)
                    // Заполняем значение обязательных колонок, чтобы дублировать в каждый класс
                    if (mb_strlen($item)) {
                        if ($col == $col_day_of_week)
                            $day_of_week = mb_strtoupper($item);
                        if ($col == $col_number_of_lesson)
                            $number_of_lesson = $item;
                        if ($col == $col_time_of_lesson)
                            $time_of_lesson = $item;
                    }

                // Все классы в массиве имеют порядковый номер, который обрывается на цифре 16.
                // Считаем к какому номеру относится текущая ячейка массива
                $class_number = ceil(($col + 1 - $first_data_col) / $cols_on_class);


                if ($class_number > 0) {
                    // Нашли строку с названиями классов, инициализируем класс в $classes[]
                    if ($row == $rows_of_class_name) {
                        if (mb_strlen($item)) {
                            $className = mb_strtoupper($item);
                            $num_of_classes[$class_number] = $className;
                            $classes[$className] = [];
                        }
                    } elseif ($row >= $first_data_row) {
                        $cn = $num_of_classes[$class_number];
                        if (isset($classes[$cn]) && is_array($classes[$cn])) {
                            if (!isset($classes[$cn][$day_of_week]) || !is_array($classes[$cn][$day_of_week]))
                                $classes[$cn][$day_of_week] = [];

                            $num = $number_of_lesson . " | " . $time_of_lesson;
                            if (!isset($classes[$cn][$day_of_week][$num]))
                                $classes[$cn][$day_of_week][$num] = ['time' => $time_of_lesson, 'sort' => count($classes[$cn][$day_of_week])];

                            $classes[$cn][$day_of_week][$num][] = $item;
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        return [
            'error' => 'Возникла ошибка разбора файла'
        ];
    }

    foreach ($classes as $cl_index => $cl) {
        $lessons1 = 0;

        foreach ($cl as $dow_index => $dow) {
            $lessons2 = 0;

            foreach ($dow as $l)
                if (@strlen($l[0] . $l[1] . $l[2])) {
                    $lessons1++;
                    $lessons2++;
                }

            if ($lessons2 == 0)
                unset($classes[$cl_index][$dow_index]);
        }
        if ($lessons1 == 0)
            unset($classes[$cl_index]);
    }

    return ['data' => $classes];
}

//print_r($c=excelToArray('teach.xlsx'));
//$c = excelToArray('classes.xlsx');
//$d = $c['data']['11А']['ПН'];
//print_r($d); echo '<hr>';
//include_once 'ArrayToImage.php';
//print_r($b = arrayToImage('test', $d));

// Уточнена ответственность компонента: разбор таблицы.

// Отмечена граница входных данных: разбор таблицы.

// Отмечено требование к безопасному значению: разбор таблицы.

// Уточнена обработка пустого результата: разбор таблицы.

// Отмечена важность предсказуемого ответа: разбор таблицы.
