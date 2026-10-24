<?php
declare(strict_types=1);

namespace App\Core;

final class Log
{
    /** Запись в журнал действий (activity_log). */
    public static function act(string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null, ?int $userId = null): void
    {
        try {
            Db::insert('activity_log', [
                'user_id' => $userId ?? (Auth::id() ?: null),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'details' => $details !== null ? mb_substr($details, 0, 500) : null,
                'ip' => $_SERVER['REMOTE_ADDR'] ?? null,
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            self::file('activity_log failed: ' . $e->getMessage());
        }
    }

    public static function file(string $msg): void
    {
        @file_put_contents(STORAGE . '/logs/app.log', '[' . now() . '] ' . $msg . "\n", FILE_APPEND);
    }

    public static function history(string $type, int $id, ?string $old, string $new, ?int $by, ?string $comment = null): void
    {
        Db::insert('entity_status_history', [
            'entity_type' => $type, 'entity_id' => $id, 'old_status' => $old, 'new_status' => $new,
            'changed_by' => $by, 'changed_at' => now(), 'comment' => $comment,
        ]);
    }
}
