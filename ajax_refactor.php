<?php

// Вот какие периоды можем принимать (чтобы не гадать по строкам)
const PERIOD_THIS_WEEK = 'thisweek';
const PERIOD_TWO_WEEKS = '2weeks';
const PERIOD_FOUR_WEEKS = '4weeks';
const PERIOD_EIGHT_WEEKS = '8weeks';

/**
 * Тут просто берем строковые даты начала и конца по периоду
 * (например, "понедельник этой недели" и "пятница этой недели")
 */
function getPeriodStrings(string $period): array {
    return match ($period) {
        PERIOD_THIS_WEEK => ['monday this week', 'friday this week'],
        PERIOD_TWO_WEEKS => ['monday this week', 'friday this week +1 weeks'],
        PERIOD_FOUR_WEEKS => ['monday this week', 'friday this week +3 weeks'],
        PERIOD_EIGHT_WEEKS => ['monday this week', 'friday this week +7 weeks'],
        default => ['monday this week', 'friday this week'],
    };
}

/**
 * Тут из этих строк делаем объекты Bitrix DateTime
 * Чтобы с ними дальше можно было спокойно работать
 */
function getPeriodDates(string $period): array {
    [$beginStr, $endStr] = getPeriodStrings($period);
    return [
        \Bitrix\Main\Type\DateTime::createFromTimestamp(strtotime($beginStr)),
        \Bitrix\Main\Type\DateTime::createFromTimestamp(strtotime($endStr)),
    ];
}

/**
 * Получаем все рабочие дни между двумя датами
 * Суббота и воскресенье по умолчанию пропускаем, но можно настроить
 */
function getDatesInPeriod(\Bitrix\Main\Type\DateTime $begin, \Bitrix\Main\Type\DateTime $end, array $excludedDays = [6,7]): array {
    $result = [];
    // Просто двигаемся по дням от начала до конца
    for ($i = clone $begin; $i->getTimestamp() <= $end->getTimestamp(); $i->add("1 day")) {
        $dayOfWeek = (int)date("N", $i->getTimestamp()); // 1=Пн, 7=Вс
        if (in_array($dayOfWeek, $excludedDays)) {
            // Если день выходной — пропускаем
            continue;
        }
        // Красивые сокращения дней недели
        $daysMap = [1=>"Пн", 2=>"Вт", 3=>"Ср", 4=>"Чт", 5=>"Пт", 6=>"Сб", 7=>"Вс"];
        $result[] = [
            'weekday' => $daysMap[$dayOfWeek],
            'date' => $i->format("d.m.Y")
        ];
    }
    return $result;
}

/**
 * Тут просто вытаскиваем из Битрикса все группы пользователя по ID
 * Чтобы дальше можно было понять, к каким правам он относится
 */
function getUserGroups(int $userId): array {
    $groups = [];
    try {
        $res = \Bitrix\Socialnetwork\UserToGroupTable::getList([
            'filter' => ['USER_ID' => $userId],
        ]);
        while ($group = $res->fetch()) {
            $groups[] = $group['GROUP_ID'];
        }
    } catch (Exception $e) {
        // Если что-то пойдет не так — здесь можно залогировать ошибку
    }
    return $groups;
}

/**
 * Просто проверяем, что пришел от пользователя валидный период,
 * Если нет — ставим по умолчанию 'thisweek'
 */
function getValidatedPeriod(): string {
    $period = $_REQUEST['period'] ?? PERIOD_THIS_WEEK;
    $allowed = [PERIOD_THIS_WEEK, PERIOD_TWO_WEEKS, PERIOD_FOUR_WEEKS, PERIOD_EIGHT_WEEKS];
    if (!in_array($period, $allowed)) {
        $period = PERIOD_THIS_WEEK;
    }
    return $period;
}

// Основная часть скрипта — тут всё собираем

$period = getValidatedPeriod();
[$begin, $end] = getPeriodDates($period);
$dates = getDatesInPeriod($begin, $end);

$userId = isset($_REQUEST['user_id']) ? (int)$_REQUEST['user_id'] : 0;
$userGroups = $userId ? getUserGroups($userId) : [];

// Отдаем всё обратно клиенту в формате JSON — удобно для AJAX
header('Content-Type: application/json');
echo json_encode([
    'period' => $period,
    'dates' => $dates,
    'userGroups' => $userGroups,
    // Тут можно добавить еще нужные данные
]);
exit;
