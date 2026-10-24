<?php
declare(strict_types=1);

namespace App\Core;

/** Не-JSON ответ (файл, CSV, XLSX). */
final class Raw
{
    public function __construct(
        public string $mime,
        public string $body = '',
        public ?string $filename = null,
        public ?string $path = null
    ) {}

    public function send(): void
    {
        header('Content-Type: ' . $this->mime);
        if ($this->filename !== null) {
            header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($this->filename));
        }
        header('X-Content-Type-Options: nosniff');
        if ($this->path !== null) {
            header('Content-Length: ' . filesize($this->path));
            readfile($this->path);
        } else {
            header('Content-Length: ' . strlen($this->body));
            echo $this->body;
        }
    }
}
