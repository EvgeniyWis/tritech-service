<?php
declare(strict_types=1);

namespace App\Core;

/** Простейшая валидация входных данных. */
final class V
{
    private array $errors = [];

    public function __construct(private Request $r) {}

    public static function of(Request $r): self { return new self($r); }

    public function required(string $key, string $label, int $max = 0): self
    {
        $v = $this->r->str($key);
        if ($v === null || $v === '') {
            $this->errors[$key] = "$label: обязательное поле";
        } elseif ($max && mb_strlen($v) > $max) {
            $this->errors[$key] = "$label: не более $max символов";
        }
        return $this;
    }

    public function email(string $key = 'email'): self
    {
        $v = (string)$this->r->str($key);
        if (!filter_var($v, FILTER_VALIDATE_EMAIL) || mb_strlen($v) > 190) {
            $this->errors[$key] = 'Некорректный e-mail';
        }
        return $this;
    }

    public function phone(string $key = 'phone', bool $required = false): self
    {
        $v = (string)$this->r->str($key);
        if ($v === '') {
            if ($required) {
                $this->errors[$key] = 'Укажите телефон';
            }
        } elseif (!preg_match('/^\+?[0-9\s\-\(\)]{10,20}$/', $v)) {
            $this->errors[$key] = 'Некорректный телефон';
        }
        return $this;
    }

    public function password(string $key = 'password'): self
    {
        $v = (string)$this->r->input($key, '');
        $min = (int)cfg('security.password_min_len', 8);
        if (mb_strlen($v) < $min || !preg_match('/[A-Za-zА-Яа-я]/u', $v) || !preg_match('/\d/', $v)) {
            $this->errors[$key] = "Пароль: не менее $min символов, буквы и цифры";
        }
        return $this;
    }

    public function enum(string $key, array $allowed, string $label): self
    {
        if (!in_array($this->r->input($key), $allowed, true)) {
            $this->errors[$key] = "$label: недопустимое значение";
        }
        return $this;
    }

    public function check(): void
    {
        if ($this->errors) {
            throw HttpException::bad(reset($this->errors), $this->errors);
        }
    }

    public function fail(string $key, string $msg): void
    {
        $this->errors[$key] = $msg;
        $this->check();
    }
}
