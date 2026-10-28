<?php

if (!defined('APP_DASHBOARD_APP_API_UPLOAD_UPLOAD_MAIN_PHP_FIELD_KEY_ERROR')) { define('APP_DASHBOARD_APP_API_UPLOAD_UPLOAD_MAIN_PHP_FIELD_KEY_ERROR', 'error'); }
require_once $_SERVER["DOCUMENT_ROOT"] . '/functions/core.php';

Auth::requireAuth(true, false, true);

header('Content-Type: application/json; charset=utf-8');

$result_data = [];
$file = $_FILES['file'] ?? null;
$maxFileSize = 15 * 1024 * 1024;

// Разрешённые расширения
$allowed_extensions = ['xlsx'];
$commonFlag = ($_GET["common"] ?? '') === "true";

if (!$file) {
    $result_data[\APP_DASHBOARD_APP_API_UPLOAD_UPLOAD_MAIN_PHP_FIELD_KEY_ERROR] = 'Файл не был загружен.';
} elseif (!empty($file['error'])) {
    // Обработка ошибок загрузки файла
    switch ($file['error']) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            $result_data['error'] = 'Превышен допустимый размер файла.';
            break;
        case UPLOAD_ERR_PARTIAL:
            $result_data['error'] = 'Файл был получен только частично.';
            break;
        case UPLOAD_ERR_NO_FILE:
            $result_data['error'] = 'Файл не был загружен.';
            break;
        case UPLOAD_ERR_NO_TMP_DIR:
            $result_data['error'] = 'Временная директория отсутствует.';
            break;
        case UPLOAD_ERR_CANT_WRITE:
            $result_data['error'] = 'Не удалось записать файл на диск.';
            break;
        case UPLOAD_ERR_EXTENSION:
            $result_data['error'] = 'PHP-расширение остановило загрузку файла.';
            break;
        default:
            $result_data['error'] = 'Неизвестная ошибка при загрузке файла.';
            break;
    }
} elseif (($file['size'] ?? 0) > $maxFileSize) {
    $result_data['error'] = 'Размер файла превышает 15 МБ.';
} elseif (!is_uploaded_file($file['tmp_name'])) {
    $result_data['error'] = 'Некорректная загрузка файла.';
} else {
    // Проверка расширения файла
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowed_extensions, true)) {
        $result_data['error'] = 'Недопустимый тип файла. Разрешено: ' . implode(', ', $allowed_extensions);
    } else {
        $tmp_name = $file['tmp_name'];

        // Исправь чистку кеша ф в функции clearCaches() -> $days=7;
        $dir = $_SERVER["DOCUMENT_ROOT"] . '/caches';
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            http_response_code(500);
            echo json_encode(['error' => 'Не удалось создать каталог для загрузки.']);
            exit;
        }

        $target = $dir . '/' . date("Y-m-d-H-i-s") . '.xlsx';

        if (move_uploaded_file($tmp_name, $target)) {
            $table = excelToArray($target);
            if (isset($table['error']))
                $result_data['error'] = $table['error'];
            elseif (!isset($table['data']))
                $result_data['error'] = 'Ошибка разбора файла с расписанием';
            else {
                if ($commonFlag) {
                    $arrayToDatabase = commonTimeTablesToDatabase($table['data']);
                } else {
                    $arrayToDatabase = arrayToDatabase($table['data']);
                }
                if (mb_strlen($arrayToDatabase))
                    $result_data['error'] = $arrayToDatabase;
                else {
                    if (!$commonFlag) {
                        DB::execute("UPDATE users SET get_timetable = 1");
                    }
                    $result_data['file_name'] = $file['name'];
                    $result_data['file_tmp'] = $tmp_name;
                    $result_data['success'] = 'Файл успешно загружен и проверен.';
                }
            }
        } else {
            $result_data['error'] = 'Ошибка при сохранении файла.';
        }
    }
}

echo json_encode($result_data);
