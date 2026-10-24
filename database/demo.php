<?php
// Демонстрационные данные. Подключается из install.php при ключе --demo.
// Все даты формируются относительно текущего момента, чтобы дашборд выглядел «живым».
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Db;
use App\Services\Tickets;

const DEMO_PASSWORD = 'Demo12345';

function dt(int $days = 0, int $h = 0, int $m = 0): string
{
    return date('Y-m-d H:i:s', strtotime(date('Y-m-d') . " $days days $h hours $m minutes"));
}

/** Сдвигает дату на ближайший рабочий день (пн–пт). */
function wd(int $offset): int
{
    $t = strtotime(date('Y-m-d') . " $offset days");
    while ((int)date('N', $t) > 5) {
        $t += 86400 * ($offset >= 0 ? 1 : -1);
    }
    return (int)round(($t - strtotime(date('Y-m-d'))) / 86400);
}

function mkuser(string $email, string $last, string $first, ?string $mid, string $role, ?string $company = null, ?string $phone = null): int
{
    $id = Db::insert('users', [
        'email' => $email, 'password_hash' => Auth::hashPassword(DEMO_PASSWORD), 'last_name' => $last, 'first_name' => $first,
        'middle_name' => $mid, 'phone' => $phone, 'company' => $company, 'pd_consent_at' => now(), 'email_verified_at' => now(),
        'created_at' => dt(-60), 'last_login_at' => dt(-1, 10),
    ]);
    foreach ((array)$role as $rc) {
        Db::insert('user_roles', ['user_id' => $id, 'role_id' => (int)Db::val('SELECT id FROM roles WHERE code = ?', [$rc])]);
    }
    return $id;
}

// ------------------------------------------------------------ пользователи
$U = [];
$U['manager'] = mkuser('manager@tritech-service.ru', 'Фёдорова', 'Анастасия', 'Игоревна', 'manager');
$U['director'] = mkuser('director@tritech-service.ru', 'Смирнова', 'Ольга', 'Сергеевна', 'tech_director');
foreach ([
    ['volkov', 'Волков', 'Сергей', 'Андреевич'], ['orehova', 'Орехова', 'Елена', 'Павловна'], ['gromov', 'Громов', 'Павел', 'Викторович'],
] as [$k, $l, $f, $mid]) {
    $U[$k] = mkuser("$k@tritech-service.ru", $l, $f, $mid, 'consultant');
}
foreach ([
    ['petrov', 'Петров', 'Алексей', 'Николаевич'], ['sokolova', 'Соколова', 'Екатерина', 'Олеговна'], ['kuznetsov', 'Кузнецов', 'Михаил', 'Андреевич'],
    ['sergeev', 'Сергеев', 'Никита', 'Валерьевич'], ['kovaleva', 'Ковалёва', 'Дарья', 'Игоревна'],
] as [$k, $l, $f, $mid]) {
    $U[$k] = mkuser("$k@tritech-service.ru", $l, $f, $mid, 'developer');
}
foreach ([['belov', 'Белов', 'Игорь', 'Станиславович'], ['orlova', 'Орлова', 'Мария', 'Дмитриевна'], ['kim', 'Ким', 'Дмитрий', 'Андреевич']] as [$k, $l, $f, $mid]) {
    $U[$k] = mkuser("$k@tritech-service.ru", $l, $f, $mid, 'support');
}
$clients = [
    'kozlova' => ['Козлова', 'Анна', 'Викторовна', 'ИП Козлова А. В.'], 'zaharov' => ['Захаров', 'Дмитрий', 'Сергеевич', 'ООО «Альфа-Строй»'],
    'laptev' => ['Лаптев', 'Сергей', 'Николаевич', 'ИП Лаптев С. Н.'], 'vlasova' => ['Власова', 'Мария', 'Петровна', 'ООО «ВинТорг»'],
    'severov' => ['Северов', 'Олег', 'Игоревич', 'ООО «Север»'], 'meridian' => ['Меридианов', 'Игорь', 'Павлович', 'ООО «Меридиан»'],
    'markova' => ['Маркова', 'Татьяна', 'Андреевна', 'ООО «ТехноМаркет»'],
];
$i = 0;
foreach ($clients as $k => [$l, $f, $mid, $co]) {
    $U[$k] = mkuser("$k@example.com", $l, $f, $mid, 'client', $co, '+7 (900) 100-20-' . str_pad((string)(10 + $i++), 2, '0', STR_PAD_LEFT));
}

// ------------------------------------------------------------ каталог консалтинга
$spec = [];
foreach ([
    '1С: учёт и отчётность' => 'Аудит учёта, настройка и обмены в 1С',
    'Внедрение и автоматизация' => 'Проектирование и внедрение автоматизации процессов',
    'ИТ-инфраструктура и безопасность' => 'Аудит безопасности, серверы, сети, резервное копирование',
    'Интеграции и разработка' => 'Требования к интеграциям и индивидуальной разработке',
] as $n => $d) {
    $spec[$n] = Db::insert('specializations', ['name' => $n, 'description' => $d]);
}
$S = array_values($spec); // 0..3
$svc = [];
foreach ([
    ['Аудит учёта в 1С', $S[0], 60, 3500, 'Проверка корректности учёта, НДС и закрытия месяца'],
    ['Экспресс-консультация по 1С', $S[0], 30, 1800, 'Быстрый ответ на вопрос по работе в 1С'],
    ['Настройка обмена и интеграций', $S[3], 90, 5200, 'Обмен с сайтом, банком, контрагентами'],
    ['Проектирование автоматизации', $S[1], 90, 6000, 'Описание процессов и план внедрения'],
    ['Аудит ИТ-безопасности', $S[2], 60, 4500, 'Оценка рисков и рекомендации по защите'],
    ['Консультация по инфраструктуре', $S[2], 60, 3800, 'Серверы, сети, резервное копирование'],
    ['Разбор требований к разработке', $S[3], 60, 4000, 'Формулировка ТЗ и оценка трудозатрат'],
] as [$n, $sp, $dur, $price, $d]) {
    $svc[] = Db::insert('services', ['name' => $n, 'specialization_id' => $sp, 'duration_min' => $dur, 'price' => $price, 'description' => $d]);
}
$C = [];
foreach ([
    ['volkov', 'Ведущий консультант · 1С', 'Эксперт по 1С:Бухгалтерия и 1С:ERP, 12 лет практики. Аудиты учёта, настройка обменов.', 12, [$S[0], $S[3]]],
    ['orehova', 'Консультант по автоматизации', 'Проектирование процессов и внедрение учётных систем, руководитель проектов.', 9, [$S[1], $S[3]]],
    ['gromov', 'Инженер по ИТ-безопасности', 'Аудит защищённости, настройка инфраструктуры, резервное копирование.', 10, [$S[2], $S[1]]],
] as [$k, $pos, $bio, $exp, $sps]) {
    $cid = Db::insert('consultants', ['user_id' => $U[$k], 'position' => $pos, 'bio' => $bio, 'experience_years' => $exp]);
    foreach ($sps as $sp) {
        Db::insert('consultant_specializations', ['consultant_id' => $cid, 'specialization_id' => $sp]);
    }
    $C[$k] = $cid;
    // расписание: пн–пт; у Волкова и Ореховой перерыв на обед
    $slots = $k === 'gromov' ? [['11:00', '19:00']] : ($k === 'volkov' ? [['10:00', '13:00'], ['14:00', '19:00']] : [['09:00', '13:00'], ['14:00', '18:00']]);
    for ($d = 1; $d <= 5; $d++) {
        foreach ($slots as [$s, $e]) {
            Db::insert('work_schedules', ['consultant_id' => $cid, 'weekday' => $d, 'start_time' => $s, 'end_time' => $e]);
        }
    }
}
Db::insert('schedule_exceptions', ['consultant_id' => $C['gromov'], 'date' => date('Y-m-d', strtotime(dt(wd(9)))), 'is_working' => 0, 'reason' => 'Отпуск']);

// ------------------------------------------------------------ записи
$ST = array_column(Db::all('SELECT code, id FROM appointment_statuses'), 'id', 'code');
$appts = [];
$notesText = [
    'summary' => 'Проведён аудит учёта: найдены расхождения в настройках учётной политики и закрытии месяца.',
    'recs' => "1. Обновить конфигурацию до актуального релиза.\n2. Пересмотреть настройки регламентных операций.\n3. Провести обучение сотрудников бухгалтерии.",
];
/** @return int id записи */
function make_app(array $U, array $C, array $svc, string $client, string $cons, int $svcIdx, int $dayOff, string $hm, string $status, array $o = []): int
{
    global $ST;
    $dayOff = wd($dayOff);
    $start = dt($dayOff, (int)substr($hm, 0, 2), (int)substr($hm, 3, 2));
    $s = Db::one('SELECT * FROM services WHERE id = ?', [$svc[$svcIdx]]);
    $end = date('Y-m-d H:i:s', strtotime($start) + $s['duration_min'] * 60);
    if ($status === 'completed' && strtotime($end) > time()) {
        $status = 'confirmed'; // консультация ещё не состоялась
    }
    $created = date('Y-m-d H:i:s', min(strtotime($start) - 3 * 86400, time() - 3600));
    $paid = !in_array($status, ['pending_payment'], true) && !($status === 'cancelled' && empty($o['was_paid']));
    $id = Db::insert('appointments', [
        'client_id' => $U[$client], 'consultant_id' => $C[$cons], 'service_id' => $svc[$svcIdx], 'status_id' => $ST[$status],
        'start_at' => $start, 'end_at' => $end, 'price' => $s['price'],
        'payment_deadline' => $status === 'pending_payment' ? date('Y-m-d H:i:s', time() + 27 * 60) : null,
        'client_comment' => $o['comment'] ?? null, 'meeting_url' => 'https://meet.jit.si/Tritech-' . bin2hex(random_bytes(5)),
        'cancelled_by' => $status === 'cancelled' ? ($o['by'] ?? 'client') : null, 'cancel_reason' => $o['reason'] ?? null,
        'cancelled_at' => $status === 'cancelled' ? date('Y-m-d H:i:s', strtotime($start) - 3 * 86400) : null,
        'created_at' => $created,
    ]);
    Db::insert('status_history', ['appointment_id' => $id, 'new_status_id' => $ST['pending_payment'], 'changed_by' => $U[$client], 'changed_at' => $created, 'comment' => 'Запись создана, ожидается оплата']);
    if ($paid) {
        $pid = Db::insert('payments', ['appointment_id' => $id, 'type' => 'payment', 'amount' => $s['price'], 'status' => 'succeeded', 'method' => 'bank_card',
            'external_id' => 'mock-' . bin2hex(random_bytes(8)), 'idempotence_key' => App\Services\YooKassa::uuid(), 'created_at' => $created, 'paid_at' => date('Y-m-d H:i:s', strtotime($created) + 240)]);
        Db::insert('status_history', ['appointment_id' => $id, 'old_status_id' => $ST['pending_payment'], 'new_status_id' => $ST['confirmed'], 'changed_at' => date('Y-m-d H:i:s', strtotime($created) + 240), 'comment' => 'Оплата получена']);
        if ($status === 'cancelled') {
            Db::insert('payments', ['appointment_id' => $id, 'parent_payment_id' => $pid, 'type' => 'refund', 'amount' => $s['price'], 'status' => 'succeeded', 'method' => 'bank_card',
                'external_id' => 'mock-refund-' . bin2hex(random_bytes(6)), 'idempotence_key' => App\Services\YooKassa::uuid(), 'created_at' => date('Y-m-d H:i:s', strtotime($start) - 3 * 86400), 'paid_at' => date('Y-m-d H:i:s', strtotime($start) - 3 * 86400)]);
        }
    }
    if (!in_array($status, ['pending_payment', 'confirmed'], true)) {
        Db::insert('status_history', ['appointment_id' => $id, 'old_status_id' => $ST['confirmed'], 'new_status_id' => $ST[$status], 'changed_at' => $status === 'cancelled' ? date('Y-m-d H:i:s', strtotime($start) - 3 * 86400) : $end,
            'changed_by' => $status === 'cancelled' ? $U[$client] : $U[$cons], 'comment' => $status === 'cancelled' ? 'Отменена клиентом, возврат 100%' : null]);
    }
    return $id;
}

$notes = function (int $appId, string $cons, bool $final = true) use ($U, $notesText) {
    Db::insert('consultation_notes', ['appointment_id' => $appId, 'author_id' => $U[$cons], 'version' => 1, 'status' => $final ? 'final' : 'draft',
        'summary' => $notesText['summary'], 'recommendations' => $notesText['recs'],
        'created_at' => (string)Db::val('SELECT end_at FROM appointments WHERE id = ?', [$appId])]);
};
$review = function (int $appId, string $client, string $cons, int $rating, string $text, string $status = 'published') use ($U, $C) {
    Db::insert('reviews', ['appointment_id' => $appId, 'client_id' => $U[$client], 'consultant_id' => $C[$cons], 'rating' => $rating, 'text' => $text,
        'status' => $status, 'moderated_by' => $status === 'pending' ? null : $U['manager'], 'moderated_at' => $status === 'pending' ? null : dt(-1), 'created_at' => dt(-1)]);
};

$a1 = make_app($U, $C, $svc, 'kozlova', 'volkov', 0, -40, '10:00', 'completed', ['comment' => 'Хотим проверить корректность учёта НДС и закрытие месяца']);
$notes($a1, 'volkov'); $review($a1, 'kozlova', 'volkov', 5, 'Очень детально разобрали наш учёт, получили чёткий план исправлений.');
$a2 = make_app($U, $C, $svc, 'zaharov', 'orehova', 3, -35, '11:00', 'completed', ['comment' => 'Нужен личный кабинет дилеров и обмен с 1С']);
$notes($a2, 'orehova'); $review($a2, 'zaharov', 'orehova', 5, 'Елена структурировала требования, на их основе сразу запустили проект.');
$a3 = make_app($U, $C, $svc, 'laptev', 'gromov', 4, -30, '12:00', 'completed');
$notes($a3, 'gromov'); $review($a3, 'laptev', 'gromov', 4, 'Полезные рекомендации по резервному копированию.');
$a4 = make_app($U, $C, $svc, 'vlasova', 'volkov', 2, -26, '14:00', 'completed', ['comment' => 'Настройка приёма платежей на сайте и возвратов']);
$notes($a4, 'volkov'); $review($a4, 'vlasova', 'volkov', 5, 'Всё по делу, спасибо за разбор схемы возвратов.', 'pending');
$a5 = make_app($U, $C, $svc, 'kozlova', 'orehova', 6, -21, '14:00', 'completed');
$notes($a5, 'orehova'); $review($a5, 'kozlova', 'orehova', 4, 'Хорошая консультация, не хватило времени на все вопросы.');
$a6 = make_app($U, $C, $svc, 'zaharov', 'volkov', 1, -14, '15:00', 'completed');
$notes($a6, 'volkov'); $review($a6, 'zaharov', 'volkov', 5, 'Быстро и точно.');
$a7 = make_app($U, $C, $svc, 'severov', 'gromov', 5, -9, '11:00', 'completed');
$notes($a7, 'gromov');
make_app($U, $C, $svc, 'meridian', 'volkov', 0, -7, '10:00', 'no_show');
make_app($U, $C, $svc, 'vlasova', 'orehova', 3, -5, '14:00', 'cancelled', ['was_paid' => true, 'reason' => 'Изменились планы']);
$a10 = make_app($U, $C, $svc, 'markova', 'volkov', 1, -3, '16:00', 'completed');
$notes($a10, 'volkov'); $review($a10, 'markova', 'volkov', 5, 'Решили вопрос за 30 минут.', 'pending');

make_app($U, $C, $svc, 'kozlova', 'volkov', 0, 0, '10:00', 'completed');
make_app($U, $C, $svc, 'zaharov', 'orehova', 6, 0, '11:00', 'completed');
make_app($U, $C, $svc, 'laptev', 'gromov', 5, 0, '15:00', 'confirmed');
make_app($U, $C, $svc, 'severov', 'volkov', 1, 0, '17:00', 'confirmed');
make_app($U, $C, $svc, 'kozlova', 'volkov', 0, 1, '11:00', 'confirmed', ['comment' => 'Проверить корректность учёта НДС и настроить закрытие месяца в 1С:Бухгалтерии 3.0']);
make_app($U, $C, $svc, 'meridian', 'orehova', 3, 2, '10:00', 'confirmed');
make_app($U, $C, $svc, 'vlasova', 'gromov', 4, 3, '14:00', 'confirmed');
make_app($U, $C, $svc, 'markova', 'volkov', 1, 5, '15:00', 'confirmed');
make_app($U, $C, $svc, 'laptev', 'orehova', 6, 6, '16:00', 'confirmed');
make_app($U, $C, $svc, 'zaharov', 'volkov', 2, 2, '16:00', 'pending_payment', ['comment' => 'Интеграция с банком для выгрузки платёжных поручений']);

// ------------------------------------------------------------ проекты и задачи
$PR = array_column(Db::all('SELECT code, id FROM priorities'), 'id', 'code');
function mkproject(array $U, string $client, ?int $app, string $owner, string $name, string $descr, string $status, int $startOff, ?int $endOff, array $members): int
{
    $id = Db::insert('projects', ['client_id' => $U[$client], 'appointment_id' => $app, 'owner_id' => $U[$owner], 'name' => $name, 'description' => $descr, 'status' => $status,
        'start_date' => date('Y-m-d', strtotime(dt($startOff))), 'end_date' => $endOff === null ? null : date('Y-m-d', strtotime(dt($endOff))), 'created_at' => dt($startOff, 10)]);
    Db::insert('entity_status_history', ['entity_type' => 'project', 'entity_id' => $id, 'new_status' => 'planned', 'changed_by' => $U[$owner], 'changed_at' => dt($startOff, 10), 'comment' => $app ? 'Создан по итогам консультации' : 'Проект создан']);
    if ($status !== 'planned') {
        Db::insert('entity_status_history', ['entity_type' => 'project', 'entity_id' => $id, 'old_status' => 'planned', 'new_status' => $status, 'changed_by' => $U[$owner], 'changed_at' => dt($startOff + 2, 10)]);
    }
    foreach ($members as $uk => [$role, $alloc]) {
        Db::insert('project_members', ['project_id' => $id, 'user_id' => $U[$uk], 'role_in_project' => $role, 'allocation_percent' => $alloc, 'joined_at' => date('Y-m-d', strtotime(dt($startOff)))]);
    }
    return $id;
}
$p1 = mkproject($U, 'zaharov', $a2, 'sokolova', 'Портал дилеров', 'Личный кабинет дилеров: каталог с остатками, заказы, обмен с 1С.', 'in_progress', -30, 55,
    ['sokolova' => ['lead', 60], 'petrov' => ['developer', 100], 'kuznetsov' => ['developer', 100], 'sergeev' => ['tester', 50]]);
$p2 = mkproject($U, 'kozlova', $a1, 'petrov', 'Внедрение 1С:Бухгалтерия 3.0', 'Перенос учёта, настройка закрытия месяца и обменов.', 'in_progress', -38, 20,
    ['petrov' => ['lead', 40], 'kovaleva' => ['developer', 80]]);
$p3 = mkproject($U, 'vlasova', $a4, 'sokolova', 'Интеграция сайта с ЮKassa', 'Приём платежей и возвраты для интернет-магазина.', 'testing', -24, 8,
    ['sokolova' => ['lead', 40], 'kovaleva' => ['developer', 60], 'sergeev' => ['tester', 50]]);
$p4 = mkproject($U, 'zaharov', null, 'director', 'Сопровождение', 'Задачи, созданные из заявок технической поддержки', 'support', -20, null, ['petrov' => ['developer', 20], 'kuznetsov' => ['developer', 20]]);

$tid = [];
function mktask(array $U, array $PR, int $proj, string $title, string $type, string $status, float $est, ?int $dueOff, ?string $assignee, string $prio, ?int $ticket = null, int $pos = 0): int
{
    $id = Db::insert('tasks', ['project_id' => $proj, 'ticket_id' => $ticket, 'title' => $title, 'type' => $type, 'status' => $status, 'priority_id' => $PR[$prio],
        'assignee_id' => $assignee ? $U[$assignee] : null, 'reporter_id' => $U['director'], 'estimate_hours' => $est,
        'due_date' => $dueOff === null ? null : date('Y-m-d', strtotime(dt($dueOff))), 'position' => $pos, 'created_at' => dt(-25, 11),
        'completed_at' => $status === 'done' ? dt(($dueOff ?? -3) + (($dueOff ?? 0) < 0 ? -1 : -2), 15) : null]);
    Db::insert('entity_status_history', ['entity_type' => 'task', 'entity_id' => $id, 'new_status' => 'backlog', 'changed_by' => $U['director'], 'changed_at' => dt(-25, 11), 'comment' => $ticket ? 'Создана из заявки техподдержки' : 'Задача создана']);
    if ($status !== 'backlog') {
        Db::insert('entity_status_history', ['entity_type' => 'task', 'entity_id' => $id, 'old_status' => 'backlog', 'new_status' => $status, 'changed_by' => $assignee ? $U[$assignee] : null, 'changed_at' => dt(-10, 12)]);
    }
    return $id;
}
$T = [];
$T['export'] = mktask($U, $PR, $p1, 'Экспорт заказов дилеров в 1С', 'feature', 'backlog', 12, 14, 'kuznetsov', 'medium', null, 1);
mktask($U, $PR, $p1, 'Фильтр по региону в каталоге', 'improvement', 'backlog', 6, 16, 'kuznetsov', 'low', null, 2);
mktask($U, $PR, $p1, 'Исследовать интеграцию с «Честным знаком»', 'research', 'backlog', 8, 20, 'petrov', 'medium', null, 3);
mktask($U, $PR, $p1, 'Уведомления дилерам о смене статуса заказа', 'feature', 'backlog', 12, 24, 'sokolova', 'medium', null, 4);
$T['lk'] = mktask($U, $PR, $p1, 'Личный кабинет дилера: история заказов', 'feature', 'in_progress', 16, 8, 'petrov', 'high', null, 1);
$T['yk'] = mktask($U, $PR, $p1, 'Интеграция с ЮKassa: возвраты', 'feature', 'in_progress', 10, -4, 'sokolova', 'high', null, 2);
$T['print'] = mktask($U, $PR, $p1, 'Печатная форма счёта-оферты', 'feature', 'testing', 6, 2, 'kuznetsov', 'medium', null, 1);
$T['auth'] = mktask($U, $PR, $p1, 'Авторизация дилеров по e-mail', 'feature', 'done', 12, -8, 'petrov', 'high', null, 1);
$T['cat'] = mktask($U, $PR, $p1, 'Каталог товаров с остатками', 'feature', 'done', 16, -6, 'kuznetsov', 'medium', null, 2);
$T['round'] = mktask($U, $PR, $p1, 'Ошибка округления цены в корзине', 'bug', 'done', 2, -2, 'sokolova', 'medium', null, 3);
$T['p2a'] = mktask($U, $PR, $p2, 'Перенос остатков и взаиморасчётов', 'feature', 'done', 24, -12, 'kovaleva', 'high');
$T['p2b'] = mktask($U, $PR, $p2, 'Настройка регламентного закрытия месяца', 'feature', 'in_progress', 14, 3, 'kovaleva', 'high');
mktask($U, $PR, $p2, 'Обучение бухгалтерии', 'research', 'backlog', 8, 12, 'petrov', 'low');
mktask($U, $PR, $p3, 'Приём платежей на сайте', 'feature', 'done', 20, -10, 'kovaleva', 'high');
$T['p3b'] = mktask($U, $PR, $p3, 'Обработка webhook и возвраты', 'feature', 'testing', 12, 1, 'sokolova', 'critical');
mktask($U, $PR, $p3, 'Чек 54-ФЗ при оплате', 'improvement', 'in_progress', 8, 4, 'kovaleva', 'medium');

// ------------------------------------------------------------ заявки техподдержки
$cat = array_column(Db::all('SELECT name, id FROM ticket_categories'), 'id', 'name');
$tmpl = [
    ['Приветствие и запрос данных', null, "Здравствуйте! Заявку взяли в работу. Уточните, пожалуйста, версию конфигурации и приложите скриншот ошибки."],
    ['Запрос доступа к базе', $cat['Доступ и учётные записи'], "Для диагностики нам потребуется временный доступ к копии базы. Подключение выполним в согласованное время."],
    ['Решение: обновление', $cat['Настройка и обновление'], "Обновление выполнено, конфигурация работает на актуальном релизе. Проверьте, пожалуйста, и сообщите о результате."],
    ['Передано разработчикам', $cat['Ошибка в ПО'], "Ошибка воспроизведена и передана в разработку. О результатах сообщим сразу после исправления."],
    ['Заявка решена', null, "Заявка решена. Если проблема повторится — ответьте на это сообщение, и мы вернём её в работу."],
    ['Ожидаем ответа клиента', null, "Мы не получили ответ на наш запрос. Пожалуйста, пришлите уточнения — без них мы не сможем продолжить работу."],
];
foreach ($tmpl as [$t, $c, $b]) {
    Db::insert('response_templates', ['title' => $t, 'category_id' => $c, 'body' => $b, 'created_by' => $U['belov'], 'created_at' => dt(-30)]);
}

$support = ['belov', 'orlova', 'kim'];
$tickets = [];
/** age — минут с момента создания */
function mkticket(array $U, array $cat, array $PR, string $client, string $category, string $prio, string $subject, string $descr, string $channel, string $status, int $ageMin, ?string $assignee, array $o = []): int
{
    $created = new DateTimeImmutable(date('Y-m-d H:i:s', time() - $ageMin * 60));
    $prioId = $PR[$prio];
    [$respDue, $due] = Tickets::slaFor($prioId, $created);
    $w = (int)Db::val('SELECT weight FROM priorities WHERE id = ?', [$prioId]);
    $load = $assignee ? Tickets::load($U[$assignee]) : null;
    $resolved = null;
    if (in_array($status, ['resolved', 'closed'], true)) {
        $late = !empty($o['late']);
        $resolved = $late ? date('Y-m-d H:i:s', strtotime($due) + 3600) : date('Y-m-d H:i:s', max(strtotime($created->format('Y-m-d H:i:s')) + 1800, strtotime($due) - ($o['margin'] ?? 7200)));
        if (strtotime($resolved) > time()) {
            $resolved = date('Y-m-d H:i:s', time() - 600);
        }
    }
    $first = $status === 'new' ? null : date('Y-m-d H:i:s', $created->getTimestamp() + ($o['resp'] ?? 900));
    $id = Db::insert('tickets', [
        'client_id' => $U[$client], 'category_id' => $cat[$category], 'priority_id' => $prioId, 'assignee_id' => $assignee ? $U[$assignee] : null,
        'subject' => $subject, 'description' => $descr, 'channel' => $channel, 'status' => $status, 'first_response_due' => $respDue, 'due_at' => $due,
        'first_response_at' => $first, 'resolved_at' => $resolved, 'closed_at' => $status === 'closed' ? $resolved : null,
        'auto_assigned' => $assignee ? 1 : 0, 'assigned_load' => $load, 'created_by' => $U[$client], 'created_at' => $created->format('Y-m-d H:i:s'),
        'project_id' => $o['project'] ?? null, 'sla_breach_notified' => 0,
    ]);
    $c = $created->format('Y-m-d H:i:s');
    Db::insert('entity_status_history', ['entity_type' => 'ticket', 'entity_id' => $id, 'new_status' => 'new', 'changed_by' => $U[$client], 'changed_at' => $c,
        'comment' => 'Заявка создана (' . ['web' => 'веб-форма', 'email' => 'e-mail', 'phone' => 'телефон'][$channel] . '), рассчитаны сроки SLA']);
    if ($assignee) {
        Db::insert('entity_status_history', ['entity_type' => 'ticket', 'entity_id' => $id, 'old_status' => 'new', 'new_status' => 'new', 'changed_at' => $c, 'comment' => 'Назначена автоматически (нагрузка L = ' . $load . ')']);
    }
    if ($status !== 'new') {
        Db::insert('entity_status_history', ['entity_type' => 'ticket', 'entity_id' => $id, 'old_status' => 'new', 'new_status' => $status === 'closed' || $status === 'resolved' ? 'in_progress' : $status, 'changed_by' => $assignee ? $U[$assignee] : null, 'changed_at' => $first]);
    }
    if ($resolved) {
        Db::insert('entity_status_history', ['entity_type' => 'ticket', 'entity_id' => $id, 'old_status' => 'in_progress', 'new_status' => $status, 'changed_by' => $assignee ? $U[$assignee] : null, 'changed_at' => $resolved]);
    }
    // стартовое сообщение клиента не дублируем в комментариях: описание хранится в заявке
    if ($assignee && $status !== 'new') {
        Db::insert('ticket_comments', ['ticket_id' => $id, 'author_id' => $U[$assignee], 'text' => 'Здравствуйте! Заявку взял в работу, о результатах сообщу в течение часа.', 'is_internal' => 0, 'created_at' => $first]);
    }
    return $id;
}

// нарушения / под угрозой / в срок
$t1 = mkticket($U, $cat, $PR, 'kozlova', 'Доступ и учётные записи', 'high', 'Не открывается личный кабинет сотрудника', 'После смены пароля личный кабинет выдаёт ошибку 500 при входе. Затронуты 3 сотрудника.', 'web', 'accepted', 24 * 60 + 25, 'belov');
$t2 = mkticket($U, $cat, $PR, 'zaharov', 'Ошибка в ПО', 'critical', 'Не проводятся документы реализации после обновления', "После обновления конфигурации до версии 3.0.150 при проведении документа «Реализация товаров и услуг» появляется ошибка «Неверная дата запрета редактирования». Работа отдела продаж остановлена, затронуто 6 пользователей.", 'email', 'in_progress', 5 * 60 + 55, 'belov', ['project' => $p4, 'resp' => 720]);
$t3 = mkticket($U, $cat, $PR, 'vlasova', 'Сбой', 'high', 'Ошибка выгрузки в ЕГАИС', 'Не проходит выгрузка накладных в ЕГАИС, УТМ возвращает ошибку подписи.', 'phone', 'in_progress', 18 * 60 + 30, 'orlova', ['project' => $p1]);
$t4 = mkticket($U, $cat, $PR, 'severov', 'Доступ и учётные записи', 'medium', 'Настроить права для нового бухгалтера', 'Нужны права на ввод первичных документов и просмотр отчётов без права закрытия периода.', 'web', 'waiting_client', 20 * 60, 'kim');
$t5 = mkticket($U, $cat, $PR, 'meridian', 'Настройка и обновление', 'medium', 'Обновить конфигурацию до 3.0.150', 'Просим обновить конфигурацию 1С:Бухгалтерия до актуального релиза в нерабочее время.', 'email', 'in_progress', 14 * 60, 'orlova');
$t6 = mkticket($U, $cat, $PR, 'laptev', 'Консультация', 'low', 'Консультация по отчёту «Остатки товаров»', 'Не сходятся остатки в отчёте и в оборотно-сальдовой ведомости.', 'phone', 'new', 90, 'kim');
$t7 = mkticket($U, $cat, $PR, 'markova', 'Ошибка в ПО', 'medium', 'Медленно работает поиск по каталогу', 'Поиск по номенклатуре занимает до 20 секунд.', 'web', 'resolved', 3 * 24 * 60, 'belov', ['margin' => 3600]);
$t8 = mkticket($U, $cat, $PR, 'zaharov', 'Запрос на доработку', 'low', 'Добавить поле «Склад» в печатную форму', 'Нужно вывести склад отгрузки в печатной форме накладной.', 'email', 'closed', 9 * 24 * 60, 'kim', ['margin' => 86400]);
$t9 = mkticket($U, $cat, $PR, 'kozlova', 'Доступ и учётные записи', 'high', 'Сброс пароля администратора базы', 'Утерян пароль администратора, нужно восстановить доступ.', 'phone', 'closed', 6 * 24 * 60, 'orlova', ['margin' => 20000]);
$t10 = mkticket($U, $cat, $PR, 'severov', 'Сбой', 'critical', 'Не запускается сервер приложений 1С', 'Служба сервера приложений падает через минуту после запуска.', 'phone', 'resolved', 4 * 24 * 60, 'belov', ['late' => true]);
$t11 = mkticket($U, $cat, $PR, 'vlasova', 'Настройка и обновление', 'low', 'Настроить резервное копирование базы', 'Нужно ежедневное резервное копирование на сетевой ресурс.', 'web', 'closed', 12 * 24 * 60, 'kim', ['margin' => 40000]);
$t12 = mkticket($U, $cat, $PR, 'meridian', 'Ошибка в ПО', 'high', 'Сбой обмена заказами с сайтом', 'Заказы с сайта перестали загружаться в 1С после 10:00.', 'web', 'in_progress', 8 * 60, 'orlova', ['project' => $p4]);
$t13 = mkticket($U, $cat, $PR, 'zaharov', 'Ошибка в ПО', 'high', 'Не сохраняются настройки уведомлений в портале', 'В личном кабинете дилера не сохраняются настройки e-mail-уведомлений.', 'web', 'in_progress', 30 * 60, 'belov', ['project' => $p1]);
$t14 = mkticket($U, $cat, $PR, 'laptev', 'Запрос на доработку', 'medium', 'Выгрузка реестра в Excel', 'Просим добавить выгрузку реестра договоров в Excel.', 'email', 'new', 40, null);
$t15 = mkticket($U, $cat, $PR, 'markova', 'Консультация', 'low', 'Как настроить закрытие месяца', 'Подскажите порядок регламентных операций.', 'web', 'accepted', 5 * 60, 'kim');
$t16 = mkticket($U, $cat, $PR, 'kozlova', 'Сбой', 'medium', 'Зависает форма списка документов', 'Форма списка поступлений зависает при открытии.', 'web', 'resolved', 2 * 24 * 60, 'orlova', ['margin' => 5000]);

// комментарии: переписка по критической заявке
$cm = function (int $t, string $author, string $text, int $minAgo, bool $internal = false) {
    global $U;
    Db::insert('ticket_comments', ['ticket_id' => $t, 'author_id' => $U[$author], 'text' => $text, 'is_internal' => $internal ? 1 : 0, 'created_at' => date('Y-m-d H:i:s', time() - $minAgo * 60)]);
};
$cm($t2, 'zaharov', 'Срочно! После вчерашнего обновления не проводятся документы, скриншот во вложении.', 5 * 60 + 50);
$cm($t2, 'belov', 'Дмитрий, добрый день! Заявку взял в работу, подключаюсь к копии базы. О результатах сообщу в течение часа.', 5 * 60 + 40);
$cm($t2, 'belov', 'Воспроизвёл на копии базы: ошибка в модуле проведения после патча 3.0.150. Нужна правка кода — эскалирую в разработку.', 5 * 60 - 5, true);
$cm($t4, 'belov', 'Уточните, пожалуйста, должность сотрудника и перечень отчётов, которые ему нужны.', 18 * 60);
$cm($t13, 'zaharov', 'Настройки сбрасываются после выхода из кабинета.', 29 * 60);

// эскалации → задачи
$tk1 = mktask($U, $PR, $p1, 'ТП-' . str_pad((string)$t3, 5, '0', STR_PAD_LEFT) . ': Ошибка выгрузки в ЕГАИС', 'bug', 'in_progress', 6, 1, 'petrov', 'high', $t3, 4);
$tk2 = mktask($U, $PR, $p1, 'ТП-' . str_pad((string)$t13, 5, '0', STR_PAD_LEFT) . ': Не сохраняются настройки уведомлений', 'bug', 'testing', 3, 3, 'sokolova', 'high', $t13, 2);
$tk3 = mktask($U, $PR, $p4, 'ТП-' . str_pad((string)$t12, 5, '0', STR_PAD_LEFT) . ': Сбой обмена заказами с сайтом', 'bug', 'in_progress', 5, 0, 'kuznetsov', 'high', $t12, 1);
mktask($U, $PR, $p4, 'ТП-' . str_pad((string)$t2, 5, '0', STR_PAD_LEFT) . ': Не проводятся документы реализации', 'bug', 'backlog', 8, 1, 'petrov', 'critical', $t2, 2);
Db::insert('ticket_comments', ['ticket_id' => $t3, 'author_id' => $U['orlova'], 'text' => 'Эскалация в разработку: создана задача TS-' . $tk1, 'is_internal' => 1, 'created_at' => dt(0, 7)]);

// ------------------------------------------------------------ трудозатраты
$logs = [
    [$T['lk'], 'petrov', [-1 => 5, -2 => 6, -3 => 4, -6 => 3]], [$T['yk'], 'sokolova', [-1 => 4, -2 => 3, -5 => 6, -8 => 5]],
    [$T['print'], 'kuznetsov', [-1 => 3, -2 => 2, -4 => 1]], [$T['auth'], 'petrov', [-14 => 6, -13 => 6, -12 => 2]], [$T['cat'], 'kuznetsov', [-15 => 8, -12 => 8, -9 => 2]],
    [$T['round'], 'sokolova', [-5 => 2]], [$T['p2b'], 'kovaleva', [-1 => 6, -2 => 6, -3 => 5, -7 => 6]], [$T['p2a'], 'kovaleva', [-17 => 8, -16 => 8, -15 => 8]],
    [$T['p3b'], 'sokolova', [-1 => 3, -3 => 4, -6 => 5]], [$tk1, 'petrov', [0 => 2]], [$tk2, 'sokolova', [-1 => 2, 0 => 1]], [$tk3, 'kuznetsov', [0 => 2]],
];
foreach ($logs as [$task, $user, $days]) {
    foreach ($days as $off => $h) {
        $date = date('Y-m-d', strtotime(dt(wd($off))));
        if ($date > date('Y-m-d')) {
            continue;
        }
        $used = (float)Db::val('SELECT COALESCE(SUM(hours),0) FROM time_logs WHERE user_id = ? AND work_date = ?', [$U[$user], $date]);
        if ($used + $h > 24) {
            continue;
        }
        Db::insert('time_logs', ['task_id' => $task, 'user_id' => $U[$user], 'work_date' => $date, 'hours' => $h, 'comment' => 'Работа по задаче', 'created_at' => $date . ' 18:00:00']);
    }
}

// ------------------------------------------------------------ уведомления, вложение
foreach ([
    ['belov', 'ticket_assigned', 'Вам назначена заявка', 'Критический · Не проводятся документы реализации после обновления', $t2],
    ['belov', 'sla_warning', 'Срок SLA истекает', 'ТП-' . str_pad((string)$t2, 5, '0', STR_PAD_LEFT), $t2],
    ['kozlova', 'reminder', 'Напоминание: консультация завтра', 'Аудит учёта в 1С · Волков Сергей', null],
] as [$u, $type, $title, $body, $ticket]) {
    Db::insert('notifications', ['user_id' => $U[$u], 'type' => $type, 'title' => $title, 'body' => $body, 'ticket_id' => $ticket, 'created_at' => dt(0, 9)]);
}
Db::insert('notifications', ['user_id' => $U['manager'], 'type' => 'review_new', 'title' => 'Новый отзыв на модерации', 'body' => 'Волков Сергей · оценка 5', 'created_at' => dt(-1, 12)]);
Db::insert('notifications', ['user_id' => $U['director'], 'type' => 'sla_breach', 'title' => 'Нарушен SLA: ТП-' . str_pad((string)$t1, 5, '0', STR_PAD_LEFT), 'body' => 'Не открывается личный кабинет сотрудника', 'ticket_id' => $t1, 'created_at' => dt(0, 8)]);

$dir = STORAGE . '/uploads/demo';
if (!is_dir($dir)) {
    mkdir($dir, 0775, true);
}
file_put_contents($dir . '/oshibka_provedeniya.txt', "Скриншот ошибки (демо-вложение)\nНеверная дата запрета редактирования\n");
Db::insert('attachments', ['ticket_id' => $t2, 'uploaded_by' => $U['zaharov'], 'file_name' => 'oshibka_provedeniya.txt', 'file_path' => 'demo/oshibka_provedeniya.txt', 'file_size' => 80, 'mime' => 'text/plain', 'created_at' => dt(0, 7)]);

echo "Демо-данные созданы. Пароль всех демо-пользователей: " . DEMO_PASSWORD . "\n";
