<?php
declare(strict_types=1);
if (!defined('APP_WEBAPP_COMPONENTS_TIMETABLE_PHP_FIELD_KEY_CLASSES')) { define('APP_WEBAPP_COMPONENTS_TIMETABLE_PHP_FIELD_KEY_CLASSES', 'classes'); }

if (!defined('APP_WEBAPP_COMPONENTS_TIMETABLE_PHP_FIELD_KEY_SELECT')) { define('APP_WEBAPP_COMPONENTS_TIMETABLE_PHP_FIELD_KEY_SELECT', 'select'); }


require_once $_SERVER["DOCUMENT_ROOT"] . "/functions/core.php";
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

$placeholderImage = '/webapp/img/image_error.svg';

/*
|--------------------------------------------------------------------------|
| INPUT (AJAX, COOKIE, SHARE)                                              |
|--------------------------------------------------------------------------|
*/

$teacherAccount = authTeacher() ?? null;

// Текущие выбранные классы из GET или COOKIE
$selected = [];

if (!empty($_GET[\APP_WEBAPP_COMPONENTS_TIMETABLE_PHP_FIELD_KEY_SELECT])) {
    $selected = explode(',', $_GET['select']);
} elseif (!empty($_COOKIE[\APP_WEBAPP_COMPONENTS_TIMETABLE_PHP_FIELD_KEY_CLASSES])) {
    $selected = explode(',', $_COOKIE[\APP_WEBAPP_COMPONENTS_TIMETABLE_PHP_FIELD_KEY_CLASSES]);
}

// Добавляем классы из share (приоритет)
$shared = [];
if (!empty($_GET['share'])) {
    $rawShared = explode(',', $_GET['share']);
    // Валидируем по тем же правилам
    $allClasses = getLastListOfClasses();
    foreach ($rawShared as $s) {
        $s = trim($s);
        if (mb_strlen($s) <= 4 && in_array($s, $allClasses, true)) {
            $shared[] = $s;
        }
    }
}

// Убираем дубли
$selected = array_values(
    array_filter(
        array_unique(array_merge($shared, $selected)),
        function ($item) {
            return mb_strlen($item) <= 3;
        }
    )
);

// Если куки пусты, создаем
// if (empty($_COOKIE['classes']) && !empty($selected)) {
//     setcookie('classes', implode(',', $selected), time() + 3600 * 24 * 30, '/');
// }

/*
|--------------------------------------------------------------------------|
| HELPERS                                                                 |
|--------------------------------------------------------------------------|
*/

function formatDateRu(string $date): string
{
    static $days = [
    'Monday' => 'понедельник',
    'Tuesday' => 'вторник',
    'Wednesday' => 'среда',
    'Thursday' => 'четверг',
    'Friday' => 'пятница',
    'Saturday' => 'суббота',
    'Sunday' => 'воскресенье'
    ];

    static $months = [
    '01' => 'января',
    '02' => 'февраля',
    '03' => 'марта',
    '04' => 'апреля',
    '05' => 'мая',
    '06' => 'июня',
    '07' => 'июля',
    '08' => 'августа',
    '09' => 'сентября',
    '10' => 'октября',
    '11' => 'ноября',
    '12' => 'декабря'
    ];

    $ts = strtotime($date);

    return sprintf(
        '%s, %d %s',
        $days[date('l', $ts)],
        date('j', $ts),
        $months[date('m', $ts)]
    );
}

function buildImage(string $class, array $timetable, ?array $font, string $placeholder): string
{
    if (!$timetable) {
        return $placeholder;
    }

    $fontsDir = $_SERVER["DOCUMENT_ROOT"] . "/fonts";
    if (!is_dir($fontsDir)) {
        $fontsDir = __DIR__ . "/../fonts";
    }

    $regularPath = $fontsDir . "/sf_pro_display_regular.otf";
    $boldPath = $fontsDir . "/sf_pro_display_medium.otf";

    $data = arrayToImage($class, $timetable, $regularPath, $boldPath, 70);

    if (empty($data['file'])) {
        return $placeholder;
    }

    $file = $data['file'];
    $absolute = strpos($file, $_SERVER['DOCUMENT_ROOT']) === 0 ? $file : $_SERVER['DOCUMENT_ROOT'] . '/' . ltrim($file, '/');

    return file_exists($absolute) ? str_replace($_SERVER['DOCUMENT_ROOT'], '', $absolute) : $placeholder;
}

/*
|--------------------------------------------------------------------------|
| DATES                                                                    |
|--------------------------------------------------------------------------|
*/

$today = date('Y-m-d');
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$todayText = formatDateRu($today);
$tomorrowText = formatDateRu($tomorrow);

$query = http_build_query($_GET);

$currentUrl =
    (isset($_SERVER['HTTPS']) ? 'https://' : 'http://') .
    $_SERVER['HTTP_HOST'] .
    ($query ? '/?' . $query : '/');

/*
|--------------------------------------------------------------------------|
| RENDER                                                                   |
|--------------------------------------------------------------------------|
*/

$classActive = $selected;
$scheduleCount = 0;
if ($teacherAccount !== null) {
    array_unshift($classActive, $teacherAccount);
}

function renderDefaultTimetable(?string $class = '11А', int $initialDay = 2, bool $showOpened = false): void
{
    $class = htmlspecialchars($class ?? '11А');
    $weekdays = getShortWeekdays(); ?>

    <div class="timetable__default swiper swiper-default" data-initial-day="<?= $initialDay ?>"
        data-opened="<?= $showOpened ? "true" : "false" ?>">
        <div class="swiper-wrapper">

            <?php foreach ($weekdays as $day):
                $timetable = getLessonsByDateFromCommonTtimeTable($day, $class); ?>
                <div class="swiper-slide">
                    <div class="timetable__default-item">
                        <?php if (empty($timetable)): ?>
                            <div class="timetable__default-item-header css--default-timetable-empty">
                                <b><?= getFullWeekdayName($day) ?></b>,
                                <?= $class ?>,
                                постоянное расписание<br>
                            </div>
                            <ul class="timetable__default-item-list">
                                <li class="time">еще не было загружено</li>
                            </ul>
                        <?php else: ?>
                            <div class="timetable__default-item-header">
                                <?= getScheduleEmoji($showOpened) ?>
                                <b><?= getFullWeekdayName($day) ?></b>,
                                <?= $class ?>,
                                постоянное расписание<br>
                            </div>

                            <ul class="timetable__default-item-list">
                                <?php foreach ($timetable as $lesson): ?>
                                    <li data-state="<?= (mb_strlen($lesson[0]) === 0) ? 'false' : 'true' ?>">
                                        <span class="time">
                                            <?= htmlspecialchars($lesson['time']) ?>
                                        </span>
                                        <b>
                                            <?= htmlspecialchars(formatLessonSubject($lesson[0])) ?>
                                        </b>
                                        <span class="teacher">
                                            <?= htmlspecialchars($lesson[1]) ?>
                                        </span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </div>

            <?php endforeach; ?>
        </div>

        <div class="swiper-button-prev"></div>
        <div class="swiper-button-next"></div>
    </div>

    <?php
}

function renderControls(string $shareUrl, bool $showDefaultTimetableBtn = true): void
{ ?>
    <div class="timetable__item-controls">
        <?php if ($showDefaultTimetableBtn): ?>
            <button class="timetable__item-action js-timetable-default">
                <div class="timetable__item-btn">
                    <svg width="800px" height="800px" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path
                            d="M19.1835 7.80516L16.2188 4.83755C14.1921 2.8089 13.1788 1.79457 12.0904 2.03468C11.0021 2.2748 10.5086 3.62155 9.5217 6.31506L8.85373 8.1381C8.59063 8.85617 8.45908 9.2152 8.22239 9.49292C8.11619 9.61754 7.99536 9.72887 7.86251 9.82451C7.56644 10.0377 7.19811 10.1392 6.46145 10.3423C4.80107 10.8 3.97088 11.0289 3.65804 11.5721C3.5228 11.8069 3.45242 12.0735 3.45413 12.3446C3.45809 12.9715 4.06698 13.581 5.28476 14.8L6.69935 16.2163L2.22345 20.6964C1.92552 20.9946 1.92552 21.4782 2.22345 21.7764C2.52138 22.0746 3.00443 22.0746 3.30236 21.7764L7.77841 17.2961L9.24441 18.7635C10.4699 19.9902 11.0827 20.6036 11.7134 20.6045C11.9792 20.6049 12.2404 20.5358 12.4713 20.4041C13.0192 20.0914 13.2493 19.2551 13.7095 17.5825C13.9119 16.8472 14.013 16.4795 14.2254 16.1835C14.3184 16.054 14.4262 15.9358 14.5468 15.8314C14.8221 15.593 15.1788 15.459 15.8922 15.191L17.7362 14.4981C20.4 13.4973 21.7319 12.9969 21.9667 11.9115C22.2014 10.826 21.1954 9.81905 19.1835 7.80516Z"
                            fill="#FFFFFF" />
                    </svg>
                </div>
                <span>постоянное</span>
            </button>
        <?php endif; ?>
        <div class="timetable__item-btn js-timetable-share" data-url="<?= $shareUrl ?>">
            <svg width="800px" height="800px" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                <g id="Layer_2" data-name="Layer 2">
                    <g id="invisible_box" data-name="invisible box">
                        <rect width="48" height="48" fill="none" />
                    </g>
                    <g id="Q3_icons" data-name="Q3 icons">
                        <path
                            d="M28.3,6a1.2,1.2,0,0,0-1.1,1.3V17.9C12,19.4,2.2,29.8,2,40.3c0,.6.2,1,.6,1s.7-.3,1.1-1.1c2.4-5.4,7.8-8.5,23.5-9.2v9.7A1.2,1.2,0,0,0,28.3,42a.9.9,0,0,0,.8-.4L45.6,25.1a1.5,1.5,0,0,0,0-2L29.1,6.4a.9.9,0,0,0-.8-.4Z" />
                    </g>
                </g>
            </svg>
        </div>
    </div>
<?php } ?>

<div class="timetable">
    <?php
    $year = date('Y');

    $summerEventStart = $year . '-06-02';
    $summerEventEnd = $year . '-08-28';

    if (count($classActive) > 0 && $today > $summerEventStart && $today <= $summerEventEnd): ?>
        <article class="timetable__item item--clear">
            <svg width="134" height="309" viewBox="0 0 134 309" fill="none" xmlns="http://www.w3.org/2000/svg">
                <path d="M133.146 300.306L42.0627 308.343L40.4603 77.8771L1.34326 65.6329L133.146 0.803131V300.306Z"
                    fill="black" stroke="black" />
            </svg>
            <div class="timetable__item-title">
                Расписания нет
            </div>
            <div class="timetable__item-text">
                лето, нужно отдыхать
            </div>
        </article>
    <?php else: ?>
        <?php if (count($classActive) > 0): ?>
            <?php foreach ($classActive as $class): ?>
                <?php
                $todayLessons = getLessonsByDate($today, $class);
                $tomorrowLessons = getLessonsByDate($tomorrow, $class);

                $todayImage = buildImage($class, $todayLessons, $font ?? null, $placeholderImage);
                $tomorrowImage = buildImage($class, $tomorrowLessons, $font ?? null, $placeholderImage);

                $isShared = in_array($class, $shared, true);
                $isTodayPlaceholder = ($todayImage === $placeholderImage);
                $isTomorrowPlaceholder = ($tomorrowImage === $placeholderImage);

                $todayTag = $isTodayPlaceholder ? 'a' : 'div';
                $tomorrowTag = $isTomorrowPlaceholder ? 'a' : 'div';

                $parsed = parse_url($currentUrl);

                parse_str($parsed['query'] ?? '', $query);
                $query['share'] = $class;

                $host = "{$parsed['scheme']}://{$parsed['host']}";
                $shareUrl = htmlspecialchars(
                    ($parsed['scheme'] ?? '') ?
                    "$host{$parsed['path']}?" . http_build_query($query)
                    : $parsed['path'] . '?' . http_build_query($query)
                );
                $isTeacher = mb_strlen($class) > 3; ?>
                <?php if ($tomorrowLessons && count($tomorrowLessons) > 0): ?>
                    <article class="timetable__item item--tomorrow js-timetable-tomorrow<?= $isShared ? ' item--shared' : '' ?>"
                        data-lteacher="<?= $isTeacher ? "true" : "false" ?>">
                        <a class="timetable__item-image" data-fancybox="gallery" data-caption="<?= $class ?> завтра"
                            href="<?= htmlspecialchars($isTomorrowPlaceholder ? CLIENT_BOT_URL : $tomorrowImage) ?>" target="_blank"
                            rel="noopener">
                            <img src="<?= htmlspecialchars($tomorrowImage) ?>" loading="lazy" alt="">
                        </a>

                        <div class="timetable__item-content">
                            <div class="timetable__item-group">
                                <div class="timetable__item-title">
                                    <svg width="17" height="17" viewBox="0 0 17 17" fill="none" xmlns="http://www.w3.org/2000/svg">
                                        <path
                                            d="M3.54573 9.53213L9.32573 0.594987C9.70216 -1.30626e-05 10.625 0.279273 10.625 0.983558V6.37499H13.5757C14.1343 6.37499 14.4743 6.99427 14.1707 7.46784L8.37858 16.405C7.99001 17 7.06716 16.7207 7.06716 16.0164V10.625H4.14073C4.01179 10.6286 3.88434 10.5968 3.77225 10.533C3.66017 10.4691 3.56778 10.3758 3.50513 10.263C3.44249 10.1503 3.41202 10.0225 3.41704 9.89359C3.42205 9.7647 3.46237 9.63967 3.53358 9.53213H3.54573Z"
                                            fill="black" />
                                    </svg>
                                    <?= htmlspecialchars($class) ?>, <span>расписание</span> на завтра
                                </div>
                                <div class="timetable__item-text">
                                    <?= htmlspecialchars($tomorrowText) ?>
                                </div>
                            </div>
                            <?php renderControls($isTeacher ? ($host . $todayImage) : $shareUrl, !$isTeacher); ?>
                        </div>
                        <?php renderDefaultTimetable($class, getWeekdayNumber('tomorrow')); ?>
                    </article>
                    <?php
                    $scheduleCount += 1;
                endif; ?>
                <?php
                $currentHour = (int) date('H');
                if ($currentHour < 15 && count($todayLessons) > 0): ?>
                    <article class="timetable__item<?= $isShared ? ' item--shared' : '' ?>">
                        <a class="timetable__item-image" data-fancybox="gallery" data-caption="<?= $class ?> сегодня"
                            href="<?= $isTodayPlaceholder ? CLIENT_BOT_URL : htmlspecialchars($todayImage) ?>" target="_blank"
                            rel="noopener">
                            <img src="<?= htmlspecialchars($todayImage) ?>" loading="lazy" alt="">
                        </a>

                        <div class="timetable__item-content">
                            <div class="timetable__item-group">
                                <div class="timetable__item-title">
                                    <?php if ($isShared): ?>
                                        <svg class='js-link-shared' fill="#EB2B0E" width="800px" height="800px" viewBox="0 0 32 32"
                                            version="1.1" xmlns="http://www.w3.org/2000/svg">
                                            <path
                                                d="M0 16q0-3.232 1.28-6.208t3.392-5.12 5.12-3.392 6.208-1.28q3.264 0 6.24 1.28t5.088 3.392 3.392 5.12 1.28 6.208q0 3.264-1.28 6.208t-3.392 5.12-5.12 3.424-6.208 1.248-6.208-1.248-5.12-3.424-3.392-5.12-1.28-6.208zM4 16q0 3.264 1.6 6.048t4.384 4.352 6.016 1.6 6.016-1.6 4.384-4.352 1.6-6.048-1.6-6.016-4.384-4.352-6.016-1.632-6.016 1.632-4.384 4.352-1.6 6.016zM14.016 16v-5.984q0-0.832 0.576-1.408t1.408-0.608 1.408 0.608 0.608 1.408v4h4q0.8 0 1.408 0.576t0.576 1.408-0.576 1.44-1.408 0.576h-6.016q-0.832 0-1.408-0.576t-0.576-1.44z">
                                            </path>
                                        </svg>
                                    <?php endif; ?>
                                    <?= htmlspecialchars($class) ?>, <span>расписание</span> на сегодня
                                </div>
                                <div class="timetable__item-text">
                                    <?= htmlspecialchars($todayText) ?>
                                </div>
                            </div>
                            <?php renderControls($isTeacher ? ($host . $todayImage) : $shareUrl, !$isTeacher); ?>
                        </div>
                        <?php renderDefaultTimetable($class, getWeekdayNumber('today')); ?>
                    </article>
                    <?php
                    $scheduleCount += 1;
                endif; ?>

                <?php if (!$tomorrowLessons && $currentHour >= 15):
                    $isChillDay = getWeekdayNumber() >= 4 && getWeekdayNumber() <= 7; ?>
                    <article class="timetable__item item--text <?= $isShared ? 'item--shared' : '' ?>">
                        <div class="timetable__item-title">
                            Расписание на <?= $isChillDay ? "понедельник" : "завтра" ?> для
                            <?= htmlspecialchars($class) ?>
                        </div>
                        <div class="timetable__item-text">
                            ещё не загружено
                        </div>
                        <?php
                        if (!$isTeacher) {
                            renderDefaultTimetable($class, $isChillDay ? 0 : getWeekdayNumber('tomorrow'), true);
                        } ?>
                    </article>
                    <?php
                    $scheduleCount += 1;
                endif; ?>
            <?php endforeach; ?>
            <?php if ($scheduleCount === 0): ?>
                <article class="timetable__item item--clear">
                    <svg width="134" height="309" viewBox="0 0 134 309" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M133.146 300.306L42.0627 308.343L40.4603 77.8771L1.34326 65.6329L133.146 0.803131V300.306Z"
                            stroke="var(--color-title)" stroke-width="2" fill="var(--color-bg)" />
                    </svg>
                    <div class="timetable__item-title">
                        Расписание еще не загружено
                    </div>
                    <div class="timetable__item-text">
                        для выбранных классов расписание еще нет, но вполне может появится
                    </div>
                </article>
            <?php endif; ?>
        <?php else: ?>
            <article class="timetable__item item--clear">
                <svg width="134" height="309" viewBox="0 0 134 309" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <path d="M133.146 300.306L42.0627 308.343L40.4603 77.8771L1.34326 65.6329L133.146 0.803131V300.306Z"
                        stroke="var(--color-title)" stroke-width="2" fill="var(--color-bg)" />
                </svg>
                <div class="timetable__item-title">
                    У вас не выбран ни один класс
                </div>
                <div class="timetable__item-text">
                    выберите из списка ниже
                </div>
            </article>
        <?php endif; ?>
    <?php endif; ?>
</div>