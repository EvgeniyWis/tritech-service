<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;

final class Projects
{
    public const STATUSES = [
        'planned' => 'Планируется', 'in_progress' => 'В работе', 'testing' => 'Тестирование',
        'delivered' => 'Сдан', 'support' => 'На сопровождении', 'closed' => 'Закрыт',
    ];

    public static function isManagerial(): bool
    {
        return Auth::hasAnyRole(['manager', 'tech_director', 'admin']);
    }

    public static function find(int $id): array
    {
        $p = Db::one("SELECT p.*, COALESCE(c.company, CONCAT(c.last_name, ' ', c.first_name)) AS client_name,
                        CONCAT(o.last_name, ' ', o.first_name) AS owner_name,
                        CONCAT(YEAR(a.created_at), '-', LPAD(a.id, 4, '0')) AS appointment_number, a.start_at AS appointment_date
                      FROM projects p JOIN users c ON c.id = p.client_id JOIN users o ON o.id = p.owner_id
                      LEFT JOIN appointments a ON a.id = p.appointment_id WHERE p.id = ?", [$id]);
        if (!$p) {
            throw HttpException::notFound('Проект не найден');
        }
        return $p;
    }

    public static function isMember(int $projectId, int $userId): bool
    {
        return (bool)Db::val('SELECT 1 FROM project_members WHERE project_id = ? AND user_id = ?', [$projectId, $userId]);
    }

    /** Право читать проект. */
    public static function assertRead(array $p): void
    {
        $u = Auth::requireLogin();
        if (self::isManagerial() || (int)$p['client_id'] === $u['id'] || (int)$p['owner_id'] === $u['id'] || self::isMember((int)$p['id'], $u['id'])) {
            return;
        }
        throw HttpException::forbidden('Нет доступа к проекту');
    }

    /** Право менять задачи проекта (двигать, создавать, учитывать время). */
    public static function canWork(array $p): bool
    {
        $u = Auth::requireLogin();
        return self::isManagerial() || (int)$p['owner_id'] === $u['id'] || (Auth::hasRole('developer') && self::isMember((int)$p['id'], $u['id']));
    }

    public static function assertWork(array $p): void
    {
        if (!self::canWork($p)) {
            throw HttpException::forbidden('Вы не участвуете в этом проекте');
        }
    }

    public static function progress(int $projectId): array
    {
        $r = Db::one("SELECT COUNT(*) AS tasks_total, SUM(status = 'done') AS tasks_done,
                        COALESCE(SUM(estimate_hours), 0) AS est_total,
                        COALESCE(SUM(CASE WHEN status = 'done' THEN estimate_hours ELSE 0 END), 0) AS est_done,
                        SUM(status <> 'done' AND due_date IS NOT NULL AND due_date < CURDATE()) AS overdue
                      FROM tasks WHERE project_id = ?", [$projectId]);
        $total = (float)$r['est_total'];
        $logged = (float)Db::val('SELECT COALESCE(SUM(l.hours), 0) FROM time_logs l JOIN tasks t ON t.id = l.task_id WHERE t.project_id = ?', [$projectId]);
        return [
            'tasks_total' => (int)$r['tasks_total'], 'tasks_done' => (int)$r['tasks_done'],
            'est_total' => $total, 'est_done' => (float)$r['est_done'], 'logged_hours' => $logged,
            'overdue' => (int)$r['overdue'],
            'percent' => $total > 0 ? (int)round((float)$r['est_done'] * 100 / $total) : 0,
        ];
    }

    public static function members(int $projectId): array
    {
        return Db::all("SELECT pm.user_id, pm.role_in_project, pm.allocation_percent, pm.joined_at,
                          CONCAT(u.last_name, ' ', u.first_name) AS name, CONCAT(LEFT(u.first_name, 1), LEFT(u.last_name, 1)) AS initials
                        FROM project_members pm JOIN users u ON u.id = pm.user_id WHERE pm.project_id = ? ORDER BY pm.role_in_project, u.last_name", [$projectId]);
    }
}
