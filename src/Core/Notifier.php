<?php
declare(strict_types=1);

namespace App\Core;

/** Внутренние уведомления + дублирование на e-mail. */
final class Notifier
{
    public static function send(int $userId, string $type, string $title, ?string $body = null, array $links = [], bool $email = true): void
    {
        Db::insert('notifications', [
            'user_id' => $userId,
            'type' => $type,
            'title' => mb_substr($title, 0, 200),
            'body' => $body !== null ? mb_substr($body, 0, 500) : null,
            'appointment_id' => $links['appointment_id'] ?? null,
            'ticket_id' => $links['ticket_id'] ?? null,
            'task_id' => $links['task_id'] ?? null,
            'created_at' => now(),
        ]);
        if ($email) {
            $to = Db::val('SELECT email FROM users WHERE id = ? AND is_active = 1', [$userId]);
            if ($to) {
                $text = $title . ($body ? "\n\n" . $body : '') . "\n\n— " . cfg('app.name');
                Mailer::send($to, '[' . cfg('app.name') . '] ' . $title, $text);
            }
        }
    }

    public static function sendMany(array $userIds, string $type, string $title, ?string $body = null, array $links = [], bool $email = true): void
    {
        foreach (array_unique(array_filter($userIds)) as $uid) {
            self::send((int)$uid, $type, $title, $body, $links, $email);
        }
    }

    /** Пользователи с указанной ролью. */
    public static function usersWithRole(string $role): array
    {
        return array_map('intval', array_column(Db::all(
            'SELECT u.id FROM users u JOIN user_roles ur ON ur.user_id = u.id JOIN roles r ON r.id = ur.role_id
             WHERE r.code = ? AND u.is_active = 1', [$role]), 'id'));
    }
}
