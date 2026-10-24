<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Db;
use App\Core\HttpException;
use App\Core\Log;
use App\Core\Raw;
use App\Core\Request;
use App\Services\Projects;
use App\Services\Tasks;
use App\Services\Tickets;

final class AttachmentController
{
    private static function assertOwnerAccess(?int $ticketId, ?int $taskId, bool $write): void
    {
        if ($ticketId) {
            $t = Tickets::find($ticketId);
            Tickets::assertAccess($t);
        } elseif ($taskId) {
            $pr = Projects::find((int)Tasks::find($taskId)['project_id']);
            $write ? Projects::assertWork($pr) : Projects::assertRead($pr);
        } else {
            throw HttpException::bad('Укажите заявку или задачу');
        }
    }

    public static function upload(Request $r): array
    {
        $ticketId = $r->int('ticket_id') ?: null;
        $taskId = $r->int('task_id') ?: null;
        if (($ticketId === null) === ($taskId === null)) {
            throw HttpException::bad('Вложение привязывается к заявке или к задаче');
        }
        self::assertOwnerAccess($ticketId, $taskId, true);
        $f = $r->files['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $max = (int)(cfg('uploads.max_bytes') / 1048576);
            throw HttpException::bad(($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE ? "Файл больше $max МБ" : 'Файл не загружен');
        }
        if ($f['size'] > cfg('uploads.max_bytes')) {
            throw HttpException::bad('Размер файла не более ' . (int)(cfg('uploads.max_bytes') / 1048576) . ' МБ');
        }
        $name = preg_replace('/[\x00-\x1f\\\\\/:*?"<>|]+/u', '_', basename((string)$f['name']));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, cfg('uploads.extensions'), true)) {
            throw HttpException::bad('Недопустимый тип файла. Разрешено: ' . implode(', ', cfg('uploads.extensions')));
        }
        $dir = STORAGE . '/uploads/' . date('Y/m');
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
        $stored = bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $stored)) {
            throw new HttpException(500, 'Не удалось сохранить файл');
        }
        $mime = function_exists('mime_content_type') ? (mime_content_type($dir . '/' . $stored) ?: null) : null;
        $id = Db::insert('attachments', [
            'ticket_id' => $ticketId, 'task_id' => $taskId, 'uploaded_by' => Auth::id(), 'file_name' => $name,
            'file_path' => date('Y/m') . '/' . $stored, 'file_size' => (int)$f['size'], 'mime' => $mime, 'created_at' => now(),
        ]);
        Log::act('attachment.upload', $ticketId ? 'ticket' : 'task', $ticketId ?: $taskId, $name);
        return Db::one('SELECT id, file_name, file_size, created_at FROM attachments WHERE id = ?', [$id]);
    }

    public static function download(Request $r, array $p): Raw
    {
        $a = Db::one('SELECT * FROM attachments WHERE id = ?', [(int)$p['id']]);
        if (!$a) {
            throw HttpException::notFound('Файл не найден');
        }
        self::assertOwnerAccess($a['ticket_id'] ? (int)$a['ticket_id'] : null, $a['task_id'] ? (int)$a['task_id'] : null, false);
        $path = STORAGE . '/uploads/' . $a['file_path'];
        if (!is_file($path)) {
            throw HttpException::notFound('Файл удалён с сервера');
        }
        return new Raw('application/octet-stream', '', $a['file_name'], $path);
    }

    public static function delete(Request $r, array $p): array
    {
        $a = Db::one('SELECT * FROM attachments WHERE id = ?', [(int)$p['id']]);
        if (!$a) {
            throw HttpException::notFound('Файл не найден');
        }
        if ((int)$a['uploaded_by'] !== Auth::id() && !Auth::hasAnyRole(['support', 'manager', 'admin', 'tech_director'])) {
            throw HttpException::forbidden();
        }
        Db::exec('DELETE FROM attachments WHERE id = ?', [$a['id']]);
        @unlink(STORAGE . '/uploads/' . $a['file_path']);
        Log::act('attachment.delete', $a['ticket_id'] ? 'ticket' : 'task', (int)($a['ticket_id'] ?: $a['task_id']), $a['file_name']);
        return ['ok' => true];
    }
}
