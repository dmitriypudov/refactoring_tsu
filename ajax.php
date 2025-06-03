<?
header('Content-type: application/json');
require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

\Bitrix\Main\Loader::includeModule("tasks");
\Bitrix\Main\Loader::includeModule("im");
\Bitrix\Main\Loader::includeModule("socialnetwork");

require_once "workday.php";
require_once "tasks.php";
require_once "activities.php";

function getDatesInPeriod(
    \Bitrix\Main\Type\DateTime $begin,
    \Bitrix\Main\Type\DateTime $end
): array {
    $beginTimestamp = $begin->getTimestamp();
    $endTimestamp = $end->getTimestamp();

    $result = [];
    for (
        $i = \Bitrix\Main\Type\DateTime::createFromTimestamp($beginTimestamp);
        $i->getTimestamp() <= $endTimestamp;
        $i->add("1 day")
    ) {
        $dayOfWeek = (int) date("N", $i->getTimestamp());

        if ($dayOfWeek == 6 || $dayOfWeek == 7) {
            continue;
        }

        $result[] = [
            "weekday" => match ($dayOfWeek) {
                1 => "Пн",
                2 => "Вт",
                3 => "Ср",
                4 => "Чт",
                5 => "Пт",
            },
            "date" => $i->format("d.m.Y")
        ];
    }

    return $result;
}

function getPeriod(string $period): array
{
    [$begin, $end] = match ($period) {
        "thisweek" => ["monday this week", "friday this week"],
        "2weeks" => ["monday this week", "friday this week +1 weeks"],
        "4weeks" => ["monday this week", "friday this week +3 weeks"],
        "8weeks" => ["monday this week", "friday this week +7 weeks"],
        default => ["monday this week", "friday this week"]
    };

    return [
        \Bitrix\Main\Type\DateTime::createFromTimestamp(strtotime($begin)),
        \Bitrix\Main\Type\DateTime::createFromTimestamp(strtotime($end))
    ];
}

function isUserInWorkgroups(int $userId, array $allowedWorkgroups): bool
{
    $intersection = \Bitrix\Socialnetwork\UserToGroupTable::getList([
        "filter" => [
            "USER_ID" => $userId,
            "GROUP_ID" => $allowedWorkgroups
        ],
    ])->fetchObject();

    if ($intersection) {
        return true;
    }

    return false;
}

function getAllowedDepartments(): array
{
    $currentUser = \Bitrix\Main\Engine\CurrentUser::get();
    $departments = \Bitrix\Im\Integration\Intranet\Department::getList();

    $intersection = \Bitrix\Socialnetwork\UserToGroupTable::getList([
        "filter" => [
            "USER_ID" => $currentUser->getId(),
            "GROUP_ID" => [3042]
        ],
    ])->fetchObject();

    if (
        $intersection ||
        isUserInDepartments($currentUser->getId(), [280, 250])
    ) {
        return $departments;
    }

    return [];
}

function getFilter()
{
    $from = \Bitrix\Main\Type\DateTime::createFromTimestamp(strtotime("monday this week"));
    $to = \Bitrix\Main\Type\DateTime::createFromTimestamp(strtotime("friday this week +7 weeks"));

    $tasks = \Bitrix\Tasks\Integration\Report\Internals\TaskTable::getList([
        "select" => ["RESPONSIBLE_ID"],
        "filter" => [
            "UF_AUTO_439847723732" => 1, // СУ == 1
            ">TIME_ESTIMATE" => 0,
            "!STATUS" => 5, // Только незавершенные
            "!START_DATE_PLAN" => false,
            "!END_DATE_PLAN" => false,
            ">=END_DATE_PLAN" => $from,
            "<=START_DATE_PLAN" => $to,
        ]
    ])->fetchCollection();
    $result = [];

    $departments = getAllowedDepartments();
    $users = [];

    foreach ($tasks as $task) {
        $users[] = $task->getResponsibleId();
    }

    $users = \Bitrix\Main\UserTable::getList([
        "select" => ["ID", "NAME", "LAST_NAME", "UF_DEPARTMENT"],
        "filter" => ["ID" => $users, "ACTIVE" => "Y", "UF_DEPARTMENT" => array_keys($departments)]
    ])->fetchCollection();

    foreach ($users as $user) {
        foreach ($user->get("UF_DEPARTMENT") as $department) {
            $result[$department] ??= (object) [
                "id" => $department,
                "title" => htmlspecialchars_decode($departments[$department]["NAME"]),
                "users" => [],
                "selected" => false,
                "expanded" => false
            ];

            $result[$department]->users[] = (object) [
                "id" => $user->getId(),
                "name" => $user->getLastName() . " " . $user->getName(),
                "selected" => false,
                "expanded" => false
            ];
        }
    }

    return array_values($result);
}

try {
    $result = null;
    if ($_REQUEST["action"] === "filter") {
        $result = getFilter();
    } else {
        [$begin, $end] = getPeriod($_REQUEST["period"]);

        $tasks = getTasks($begin, $end);
        $activitiesPerUser = getActivitiesPerUser($tasks, $begin, $end);

        $departments = getAllowedDepartments();

        $users = \Bitrix\Main\UserTable::getList([
            "select" => ["ID", "NAME", "LAST_NAME", "UF_DEPARTMENT"],
            "filter" => ["ID" => array_keys($activitiesPerUser), "UF_DEPARTMENT" => array_keys($departments)]
        ])->fetchCollection();

        $structure = new stdClass;
        $structure->days = getDatesInPeriod($begin, $end);
        $structure->departments = [];
        $structure->user = \Bitrix\Main\Engine\CurrentUser::get()->getId();
        foreach ($users as $user) {
            foreach ($user->get("UF_DEPARTMENT") as $department) {
                $structure->departments[$department] ??= (object) [
                    "id" => $department,
                    "title" => htmlspecialchars_decode($departments[$department]["NAME"]),
                    "users" => [],
                ];

                $activitiesPerUser[$user->getId()]->id = $user->getId();
                $activitiesPerUser[$user->getId()]->name = $user->getLastName() . " " . $user->getName();
                $activitiesPerUser[$user->getId()]->workHoursPerDay = 8;

                $structure->departments[$department]->users[] = $activitiesPerUser[$user->getId()];
            }
        }

        $round_callback = function ($v) {
            if (is_numeric($v)) {
                return round($v, 2);
            } else {
                return $v;
            }
        };

        foreach ($structure->departments as $department) {
            foreach ($department->users as $user) {
                $user->occupation = array_map($round_callback, $user->occupation);

                foreach ($user->activities as $activity) {
                    $activity->occupation = array_map($round_callback, $activity->occupation);

                    foreach ($activity->entities as $entity) {
                        $entity->occupation = array_map($round_callback, $entity->occupation);

                        foreach ($entity->tasks as $task) {
                            $task->occupation = array_map($round_callback, $task->occupation);
                        }
                    }

                    foreach ($activity->tasks as $task) {
                        $task->occupation = array_map($round_callback, $task->occupation);
                    }
                }
            }
        }

        $structure->departments = array_values($structure->departments);
        $structure->departments2 = $departments;
        $result = $structure;
    }

    echo json_encode(["result" => $result], JSON_UNESCAPED_UNICODE);
} catch (Throwable $t) {
    echo json_encode(["error" => $t->getMessage(), "trace" => $t->getTraceAsString()], JSON_UNESCAPED_UNICODE);
}
