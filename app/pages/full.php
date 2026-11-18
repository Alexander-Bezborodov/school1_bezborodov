<?php

if (!defined('APP_APP_PAGES_FULL_PHP_FIELD_KEY_DATE')) { define('APP_APP_PAGES_FULL_PHP_FIELD_KEY_DATE', 'date'); }
require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";

$user = Auth::requireAuth();

$teachers = getLastListOfTeachers();
$classes = getLastListOfClasses();

$now = new DateTime();
$year = $now->format('Y');

// диапазон: 25 августа - 10 сентября текущего года - можно без постоянного расписания
$start = new DateTime("$year-08-25");
$end = new DateTime("$year-09-10");

$isLoadedCommon =
    getLessonsByDateFromCommonTtimeTable("Пн", $classes[5]) != []
    || ($now >= $start && $now <= $end);
//$isLoadedDefault = getLessonsByDate(getNextDayDate(), $classes[0]) != [];
//$isLoadedTeachers = getLessonsByDate(getNextDayDate(), $teachers[0]) != [];

try {
    $count = DB::selectOne('SELECT COUNT(*) as Count FROM `timetable` WHERE `date`=:date AND CHAR_LENGTH(TRIM(id))<3', [\APP_APP_PAGES_FULL_PHP_FIELD_KEY_DATE => getNextDayDate()])['Count'];
    $isLoadedDefault = $count > 0;
} catch (Exception $e) {
    $isLoadedDefault = false;
}

try {
    $count = DB::selectOne('SELECT COUNT(*) as Count FROM `timetable` WHERE `date`=:date AND CHAR_LENGTH(TRIM(id))>3', ['date' => getNextDayDate()])['Count'];
    $isLoadedTeachers = $count > 0;
} catch (Exception $e) {
    $isLoadedTeachers = false;
}


if (!$isLoadedCommon): ?>
    <div class="info">
        <span class="fav fav--info"></span>
        Для загрузки расписания на завтра необходимо прогрузить <b>Постоянное расписание</b>, <a
            href="/dashboard/app/default">перейти</a>
    </div>
<?php else: ?>
    <div id="schedule" class="schedule" data-post-schedule="false">
        <input type="file" name="file" id="schedule_filepond" />
        <div id="schedule_response" style="display:flex;flex-direction:column;gap: 5px">
            <div class="info" style="font-family: 'Inter'">
                <span class="fav fav--info"></span>
                <a style="color: black"
                    href="/dashboard/app/files/pattern.xlsx"><?= $isLoadedDefault ? "<b>(Загружено)</b>" : "" ?>
                    <code>ПРИМЕР</code> Шаблон для
                    загрузки обычного расписания</a>
            </div>
            <div class="info" style="font-family: 'Inter'">
                <span class="fav fav--info"></span>
                <a style="color: black"
                    href="/dashboard/app/files/pattern.xlsx"><?= $isLoadedTeachers ? "<b>(Загружено)</b>" : "" ?>
                    <code>ПРИМЕР</code> Шаблон для
                    загрузки учительского расписания</a>
            </div>
        </div>
    </div>

    <?php
    $teachersWithoutChatId = DB::select("
        SELECT name
        FROM users__teachers
        WHERE chat_id IS NULL OR chat_id = ''
        ORDER BY name ASC
    ");
    if (!empty($teachersWithoutChatId)): ?>
        <div class="teachers">
            Незарегистрированные учителя:
            <div class="teachers__list">
                <?php foreach ($teachersWithoutChatId as $teacher) {
                    echo "<code>" . $teacher['name'] . "</code>";
                } ?>
            </div>
        </div>
        <style>
            .teachers {
                display: flex;
                align-items: center;
                gap: 15px;
                margin-top: 35px;
                border-top: 1px solid #cccccc;
                font-size: 13px;
                padding-top: 15px;
                opacity: 0.5;
            }

            .teachers__list {
                display: flex;
                gap: 5px;
            }

            .teachers__list code {
                width: max-content;
            }
        </style>
    <?php endif; ?>
    <style>
        b {
            color: green;
        }
    </style>
<?php endif; ?>