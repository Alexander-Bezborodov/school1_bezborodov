<?php
require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";

// Принимает на вход массив:
// [
//     "1 урок" => ["time", "0", "1", "2"],
//     "2 урок" => ["time", "0", "1", "2"],
//     ...
// ]

// Array
// (
//     [1 урок] => Array
//         (
//             [time] => 8:00 - 8:40
//             [0] => Разговоры о важном
//             [1] => Кайгородова
//             [2] => 621
//         )

//     [2 урок] => Array
//         (
//             [time] => 8:50 - 9:30
//             [0] => Математика
//             [1] => Кайгородова
//             [2] => 621
//         )
// )

function arrayToImage(string $imageName, array $inputData, string $regularFont, string $boldFont, int $quality = 60): array
{
    $dir = $_SERVER["DOCUMENT_ROOT"] . '/caches';
    $filename = $dir . '/' . md5($imageName . $regularFont . $boldFont . serialize($inputData)) . '.webp';

    // Кэш
    if (file_exists($filename))
        return ['file' => $filename];

    // Проверка шрифтов
    if (!file_exists($regularFont) || !file_exists($boldFont))
        return ['error' => "Ошибка: шрифт не найден. Проверьте пути к файлам."];

    $colWidths = [0, 0, 0, 0];

    $isTeacher = !(intval($imageName) > 0 && mb_strlen($imageName) < 4);

    $header = $isTeacher ? ["Время", "Предмет", "Класс", "Каб"] : ["Время", "Предмет", "Учитель", "Каб"];

    // Создадим удобный массив

    $data = [];

    foreach ($inputData as $lesson) {
        if (!isset($lesson['time']) || !isset($lesson[0]) || !isset($lesson[1]) || !isset($lesson[2]))
            return ['error' => 'Ошибка: Для графического расписания переданы неверные данные.'];

        $subject = formatLessonSubject($lesson[0]);
        $teacher = str_replace("/", " / ", $lesson[1]);
        $data[] = [$lesson['time'], $subject, $teacher, $lesson[2]];
    }

    // Считаем сколько пустых вначале

    $empty_rows = 0;
    for ($i = 0; $i < count($data); $i++)
        if (!mb_strlen($data[$i][1] . $data[$i][2] . $data[$i][3]))
            $empty_rows++;
        else
            break;

    // Убираем уроки с конца, т.к. это можно делать только с конца!
    for ($i = count($data) - 1; $i >= 0; $i--)
        if (!mb_strlen($data[$i][1] . $data[$i][2] . $data[$i][3]))
            unset($data[$i]);
        // для классов оставляем одну пустую строку
        // для учителей - до трех
        elseif ($empty_rows < ($isTeacher ? 4 : 2))
            break;

    $fontSize = 14;
    $paddingX = 10;
    $paddingY = 5.3;

    // Определение ширины колонок

    foreach ($header as $colIndex => $text) {
        $text = strip_tags(trim($text ?? ""));
        $bbox = imagettfbbox($fontSize, 0, $regularFont, $text);
        $textWidth = $bbox[2] - $bbox[0];
        $colWidths[$colIndex] = max($colWidths[$colIndex], $textWidth + ($paddingX * 2));
    }

    foreach ($data as $row)
        foreach ($row as $colIndex => $text) {
            $text = strip_tags(trim($text ?? ""));
            $bbox = imagettfbbox($fontSize, 0, $regularFont, $text);
            $textWidth = $bbox[2] - $bbox[0];
            $colWidths[$colIndex] = max($colWidths[$colIndex], $textWidth + ($paddingX * 2));
        }

    // Высчитываем высоты строк
    $rowHeights = [];
    foreach ($data as $index => $row) {
        $maxRowHeight = 0;
        foreach ($row as $text) {
            $text = strip_tags(trim($text ?? ""));
            $bbox = imagettfbbox($fontSize, 0, $regularFont, $text);
            $textHeight = $bbox[1] - $bbox[7];
            $cellHeight = max($textHeight + ($paddingY * 2), 30);
            $maxRowHeight = max($maxRowHeight, $cellHeight);
        }
        $rowHeights[$index] = $maxRowHeight;
    }

    // Размеры изображения
    $headerHeight = 30;
    $nameHeight = $isTeacher ? round($headerHeight / 2) : 0;

    $width = array_sum($colWidths) + 1;
    $height = array_sum($rowHeights) + $nameHeight + $headerHeight + 1;

    $image = imagecreatetruecolor($width, $height);

    // Цвета
    $black = imagecolorallocate($image, 0, 0, 0);
    $headerColor = imagecolorallocate($image, 255, 255, 255);
    $redColor = imagecolorallocate($image, 255, 233, 233);
    $timeSelectedColor = imagecolorallocate($image, 208, 229, 246);
    $before1415Color = imagecolorallocate($image, 234, 248, 228);
    $exact1415Color = imagecolorallocate($image, 255, 255, 203);
    $after1415Color = imagecolorallocate($image, 219, 235, 247);
    $emptyRowColor = imagecolorallocate($image, 254, 206, 206);

    imagefilledrectangle($image, 0, 0, $width, $height, $headerColor);

    $startX = 0;
    $startY = 0;

    // Имя массива
    if ($isTeacher) {
        $teacherFontSize = round($fontSize / 2) + 1;
        $bbox = imagettfbbox($teacherFontSize, 0, $boldFont, $imageName);
        $textWidth = $bbox[2] - $bbox[0];
        $textX = round(($width - $textWidth) / 2);
        $textY = $startY + round(($nameHeight / 2) + ($teacherFontSize / 2));
        imagettftext($image, $teacherFontSize, 0, $textX, $textY, $black, $boldFont, $imageName);
        $startY += $nameHeight;
    }

    // Шапка таблицы
    foreach ($header as $index => $text) {
        $x1 = $startX;
        $y1 = $startY;
        $x2 = $x1 + $colWidths[$index];
        $y2 = $y1 + $headerHeight;

        imagefilledrectangle($image, $x1, $y1, $x2, $y2, $headerColor);
        imagerectangle($image, $x1, $y1, $x2, $y2, $black);

        $bbox = imagettfbbox($fontSize, 0, $boldFont, $text);
        $textWidth = $bbox[2] - $bbox[0];
        $textX = round($x1 + ($colWidths[$index] - $textWidth) / 2);
        $textY = $startY + round(($headerHeight / 2) + ($fontSize / 2));

        imagettftext($image, $fontSize, 0, $textX, $textY, $black, $boldFont, $text);
        $startX += $colWidths[$index];
    }

    // Заполнение таблицы
    $startY += $headerHeight;

    foreach ($data as $rowIndex => $row) {
        $time_token = mb_strpos($row[0], "*ST") !== false;
        $row[0] = str_replace("*ST", "", $row[0]);

        $time = trim($row[0] ?? "");
        $isEmpty = empty(trim(strip_tags(implode("", $row))));

        $importantText = null;
        if ($isEmpty) {
            $importantText = "Ошибка, ориентируйтесь на постоянное расписание!";
            $rowColor = $emptyRowColor;
        } elseif ($time == "14:15 - 14:55")
            $rowColor = $exact1415Color;
        elseif (strtotime(explode(" - ", $time)[0]) < strtotime("14:15"))
            $rowColor = $before1415Color;
        else
            $rowColor = $after1415Color;

        if ($time_token)
            $rowColor = $timeSelectedColor;

        $xPos = 0;
        foreach ($row as $colIndex => $text) {
            $text = strip_tags(trim($text ?? ""));
            $x1 = $xPos;
            $y1 = $startY;
            $x2 = $x1 + $colWidths[$colIndex];
            $y2 = $y1 + $rowHeights[$rowIndex];

            imagefilledrectangle($image, $x1, $y1, $x2, $y2, $rowColor);
            imagerectangle($image, $x1, $y1, $x2, $y2, $black);

            if (!empty($text)) {
                $bbox = imagettfbbox($fontSize, 0, $regularFont, $text);
                $textWidth = $bbox[2] - $bbox[0];
                $textX = round($x1 + $paddingX);
                $textY = round($y1 + ($rowHeights[$rowIndex] / 2) + ($fontSize / 2));
                if ($importantText !== null)
                    $text = $importantText;

                imagettftext($image, $fontSize, 0, $textX, $textY, $black, $regularFont, $text);
            }

            $xPos += $colWidths[$colIndex];
        }
        $startY += $rowHeights[$rowIndex];
    }

    // ob_start();
    // imagepng($image);
    // $imageData = ob_get_contents();
    // ob_end_clean();
    // imagedestroy($image);

    if (!is_dir($dir))
        mkdir($dir, 0777, true);
    //imagejpeg($image, $filename, 30);

    // Обрезаем границу вокруг - это 1 пиксель
    $width = imagesx($image);
    $height = imagesy($image);

    $cropRect = [
        'x' => 1,        // отступ слева
        'y' => 1,        // отступ сверху
        'width' => $width - 2,  // уменьшаем ширину на 2px
        'height' => $height - 2 // уменьшаем высоту на 2px
    ];

    $croppedImage = imagecrop($image, $cropRect);
    if ($croppedImage !== false) {
        imagedestroy($image); // старое изображение можно удалить
        $image = $croppedImage;
    }

    // Загругляем
    $borderColor = 0xC6C6C6; // граница
    $borderWidth = 1;         // ширина границы
    $image = roundCornersWithBorder($image, 7, $borderColor, $borderWidth);

    imagesavealpha($image, true);
    imagewebp($image, $filename, $quality);

    // return ['data' => base64_encode($imageData)];
    return ['file' => $filename];
}

function roundCornersWithBorder($image, int $radius = 15, int $borderColorRGB = 0, int $borderWidth = 2)
{
    $width = imagesx($image);
    $height = imagesy($image);

    // Создаем новое изображение с прозрачностью
    $rounded = imagecreatetruecolor($width, $height);
    imagesavealpha($rounded, true);
    $transparent = imagecolorallocatealpha($rounded, 0, 0, 0, 127);
    imagefill($rounded, 0, 0, $transparent);

    $maskColor = imagecolorallocate($rounded, 0, 0, 0);

    // Нарисовать прямоугольник с вырезанными углами
    imagefilledrectangle($rounded, $radius, 0, $width - $radius, $height, $maskColor);
    imagefilledrectangle($rounded, 0, $radius, $width, $height - $radius, $maskColor);

    imagefilledellipse($rounded, $radius, $radius, $radius * 2, $radius * 2, $maskColor);
    imagefilledellipse($rounded, $width - $radius, $radius, $radius * 2, $radius * 2, $maskColor);
    imagefilledellipse($rounded, $radius, $height - $radius, $radius * 2, $radius * 2, $maskColor);
    imagefilledellipse($rounded, $width - $radius, $height - $radius, $radius * 2, $radius * 2, $maskColor);

    // Применяем маску: копируем пиксели только из исходного изображения
    for ($x = 0; $x < $width; $x++) {
        for ($y = 0; $y < $height; $y++) {
            if (imagecolorat($rounded, $x, $y) == $maskColor) {
                imagesetpixel($rounded, $x, $y, imagecolorat($image, $x, $y));
            }
        }
    }

    // Добавляем границу
    $borderColor = imagecolorallocate($rounded, ($borderColorRGB >> 16) & 0xFF, ($borderColorRGB >> 8) & 0xFF, $borderColorRGB & 0xFF);

    // Нарисовать линии по углам и краям
    // Верхний и нижний прямоугольники
    imagefilledrectangle($rounded, $radius, 0, $width - $radius - 1, $borderWidth - 1, $borderColor);
    imagefilledrectangle($rounded, $radius, $height - $borderWidth, $width - $radius - 1, $height - 1, $borderColor);
    // Левые и правые прямоугольники
    imagefilledrectangle($rounded, 0, $radius, $borderWidth - 1, $height - $radius - 1, $borderColor);
    imagefilledrectangle($rounded, $width - $borderWidth, $radius, $width - 1, $height - $radius - 1, $borderColor);
    // Эллипсы в углах
    imagearc($rounded, $radius, $radius, $radius * 2, $radius * 2, 180, 270, $borderColor);
    imagearc($rounded, $width - $radius - 1, $radius, $radius * 2, $radius * 2, 270, 360, $borderColor);
    imagearc($rounded, $radius, $height - $radius - 1, $radius * 2, $radius * 2, 90, 180, $borderColor);
    imagearc($rounded, $width - $radius - 1, $height - $radius - 1, $radius * 2, $radius * 2, 0, 90, $borderColor);

    return $rounded;
}
// Уточнена ответственность компонента: формирование изображения.

// Уточнено назначение проверки: формирование изображения.
