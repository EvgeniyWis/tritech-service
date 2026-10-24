<?php
declare(strict_types=1);

use App\Controllers\AdminController as Adm;
use App\Controllers\AppointmentController as Apt;
use App\Controllers\AttachmentController as Att;
use App\Controllers\AuthController as Au;
use App\Controllers\CatalogController as Cat;
use App\Controllers\ClientController as Cli;
use App\Controllers\ConsultantController as Con;
use App\Controllers\DashboardController as Dash;
use App\Controllers\NotificationController as Ntf;
use App\Controllers\ProjectController as Prj;
use App\Controllers\ReportController as Rep;
use App\Controllers\TaskController as Tsk;
use App\Controllers\TicketController as Tkt;
use App\Core\Router;

$staffTP = ['support', 'manager', 'tech_director', 'admin'];
$mgmt = ['manager', 'tech_director', 'admin'];

return function (Router $r) use ($staffTP, $mgmt): void {
    // ----- Авторизация (публичные маршруты: roles = null) -----
    $r->get('/api/auth/me', [Au::class, 'me'], null);
    $r->post('/api/auth/register', [Au::class, 'register'], null);
    $r->post('/api/auth/verify', [Au::class, 'verify'], null);
    $r->post('/api/auth/resend', [Au::class, 'resend'], null);
    $r->post('/api/auth/login', [Au::class, 'login'], null);
    $r->post('/api/auth/forgot', [Au::class, 'forgot'], null);
    $r->post('/api/auth/reset', [Au::class, 'reset'], null);
    $r->post('/api/auth/logout', [Au::class, 'logout']);
    $r->put('/api/profile', [Au::class, 'updateProfile']);
    $r->post('/api/profile/password', [Au::class, 'changePassword']);

    // ----- Каталог консалтинга (публично) -----
    $r->get('/api/specializations', [Cat::class, 'specializations'], null);
    $r->get('/api/services', [Cat::class, 'services'], null);
    $r->get('/api/consultants', [Cat::class, 'consultants'], null);
    $r->get('/api/consultants/{id}', [Cat::class, 'consultant'], null);
    $r->get('/api/slots', [Cat::class, 'slots'], null);

    // ----- Записи и платежи -----
    $r->get('/api/appointments', [Apt::class, 'list']);
    $r->post('/api/appointments', [Apt::class, 'create']);
    $r->get('/api/appointments/{id}', [Apt::class, 'get']);
    $r->post('/api/appointments/{id}/cancel', [Apt::class, 'cancel']);
    $r->post('/api/appointments/{id}/reschedule', [Apt::class, 'reschedule']);
    $r->post('/api/appointments/{id}/mark', [Apt::class, 'mark'], ['consultant', 'manager', 'admin']);
    $r->post('/api/appointments/{id}/note', [Apt::class, 'saveNote'], ['consultant']);
    $r->post('/api/appointments/{id}/pay', [Apt::class, 'pay']);
    $r->post('/api/appointments/{id}/review', [Apt::class, 'review'], ['client']);
    $r->get('/api/payments/{id}', [Apt::class, 'payment']);
    $r->post('/api/payments/{id}/mock-pay', [Apt::class, 'mockPay']);
    $r->post('/api/payments/webhook', [Apt::class, 'webhook'], null);

    // ----- Консультанты: расписание, календарь, отзывы -----
    $r->get('/api/consultants/{id}/schedule', [Con::class, 'schedule'], ['consultant', 'manager', 'admin']);
    $r->put('/api/consultants/{id}/schedule', [Con::class, 'saveSchedule'], ['consultant', 'manager', 'admin']);
    $r->post('/api/consultants/{id}/exceptions', [Con::class, 'addException'], ['consultant', 'manager', 'admin']);
    $r->delete('/api/consultants/{id}/exceptions/{eid}', [Con::class, 'deleteException'], ['consultant', 'manager', 'admin']);
    $r->get('/api/consultants/{id}/calendar', [Con::class, 'calendar'], ['consultant', 'manager', 'admin']);
    $r->get('/api/reviews', [Con::class, 'reviews'], ['consultant', 'manager', 'admin']);
    $r->post('/api/reviews/{id}/moderate', [Con::class, 'moderate'], ['manager', 'admin']);

    // ----- Техподдержка -----
    $r->get('/api/ticket-meta', [Tkt::class, 'meta']);
    $r->get('/api/tickets', [Tkt::class, 'list']);
    $r->post('/api/tickets', [Tkt::class, 'create']);
    $r->get('/api/tickets/{id}', [Tkt::class, 'get']);
    $r->post('/api/tickets/{id}/comments', [Tkt::class, 'comment']);
    $r->post('/api/tickets/{id}/status', [Tkt::class, 'status']);
    $r->post('/api/tickets/{id}/assign', [Tkt::class, 'assign'], $staffTP);
    $r->post('/api/tickets/{id}/priority', [Tkt::class, 'priority'], $staffTP);
    $r->post('/api/tickets/{id}/escalate', [Tkt::class, 'escalate'], $staffTP);
    $r->get('/api/templates', [Tkt::class, 'templates'], $staffTP);
    $r->post('/api/templates', [Tkt::class, 'saveTemplate'], ['support', 'manager', 'admin']);
    $r->put('/api/templates/{id}', [Tkt::class, 'saveTemplate'], ['support', 'manager', 'admin']);
    $r->delete('/api/templates/{id}', [Tkt::class, 'deleteTemplate'], ['support', 'manager', 'admin']);
    $r->get('/api/clients', [Cli::class, 'list'], $staffTP);
    $r->get('/api/clients/{id}', [Cli::class, 'get'], $staffTP);

    // ----- Разработка -----
    $r->get('/api/projects', [Prj::class, 'list']);
    $r->post('/api/projects', [Prj::class, 'create'], $mgmt);
    $r->get('/api/projects/{id}', [Prj::class, 'get']);
    $r->put('/api/projects/{id}', [Prj::class, 'update']);
    $r->get('/api/projects/{id}/board', [Prj::class, 'board']);
    $r->post('/api/projects/{id}/members', [Prj::class, 'addMember']);
    $r->delete('/api/projects/{id}/members/{uid}', [Prj::class, 'removeMember']);
    $r->get('/api/developers', [Prj::class, 'developers'], array_merge(['developer', 'support'], $mgmt));
    $r->get('/api/tasks', [Tsk::class, 'list'], array_merge(['developer'], $mgmt));
    $r->post('/api/tasks', [Tsk::class, 'create'], array_merge(['developer'], $mgmt));
    $r->get('/api/tasks/{id}', [Tsk::class, 'get']);
    $r->put('/api/tasks/{id}', [Tsk::class, 'update']);
    $r->post('/api/tasks/{id}/move', [Tsk::class, 'move']);
    $r->post('/api/tasks/{id}/comments', [Tsk::class, 'comment']);
    $r->post('/api/tasks/{id}/timelogs', [Tsk::class, 'logTime']);
    $r->delete('/api/timelogs/{id}', [Tsk::class, 'deleteTimeLog']);
    $r->get('/api/timelogs', [Tsk::class, 'timelogs'], array_merge(['developer'], $mgmt));

    // ----- Вложения, уведомления -----
    $r->post('/api/attachments', [Att::class, 'upload']);
    $r->get('/api/attachments/{id}', [Att::class, 'download']);
    $r->delete('/api/attachments/{id}', [Att::class, 'delete']);
    $r->get('/api/notifications', [Ntf::class, 'list']);
    $r->post('/api/notifications/read-all', [Ntf::class, 'readAll']);
    $r->post('/api/notifications/{id}/read', [Ntf::class, 'read']);

    // ----- Аналитика -----
    $r->get('/api/dashboard', [Dash::class, 'get'], $mgmt);
    $r->get('/api/reports', [Rep::class, 'types'], $mgmt);
    $r->get('/api/reports/{type}', [Rep::class, 'get'], $mgmt);

    // ----- Администрирование -----
    $r->get('/api/admin/roles', [Adm::class, 'roles'], ['admin']);
    $r->get('/api/admin/users', [Adm::class, 'users'], ['admin']);
    $r->post('/api/admin/users', [Adm::class, 'createUser'], ['admin']);
    $r->get('/api/admin/users/{id}', [Adm::class, 'user'], ['admin']);
    $r->put('/api/admin/users/{id}', [Adm::class, 'updateUser'], ['admin']);
    $r->post('/api/admin/users/{id}/reset-password', [Adm::class, 'resetPassword'], ['admin']);
    $r->get('/api/admin/directories/{dir}', [Adm::class, 'directory'], ['admin']);
    $r->post('/api/admin/directories/{dir}', [Adm::class, 'saveDirectory'], ['admin']);
    $r->put('/api/admin/directories/{dir}/{id}', [Adm::class, 'saveDirectory'], ['admin']);
    $r->get('/api/admin/priorities', [Adm::class, 'priorities'], ['admin']);
    $r->put('/api/admin/priorities/{id}', [Adm::class, 'savePriority'], ['admin']);
    $r->get('/api/admin/settings', [Adm::class, 'settings'], ['admin']);
    $r->put('/api/admin/settings', [Adm::class, 'saveSettings'], ['admin']);
    $r->get('/api/admin/log', [Adm::class, 'log'], ['admin']);
    $r->get('/api/admin/backups', [Adm::class, 'backups'], ['admin']);
    $r->post('/api/admin/backups', [Adm::class, 'createBackup'], ['admin']);
    $r->get('/api/admin/backups/{name}', [Adm::class, 'downloadBackup'], ['admin']);
    $r->get('/api/admin/cron', [Adm::class, 'cronJobs'], ['admin']);
    $r->post('/api/admin/cron/{job}', [Adm::class, 'runCron'], ['admin']);
    $r->get('/api/admin/mails', [Adm::class, 'mails'], ['admin']);
};
