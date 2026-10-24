<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/**
 * Клиент API ЮKassa (v3). Без shop_id/secret_key работает режим песочницы:
 * платёж создаётся локально, оплата имитируется страницей «Оплата (тестовый режим)».
 */
final class YooKassa
{
    public static function isMock(): bool
    {
        return cfg('yookassa.shop_id') === '' || cfg('yookassa.secret_key') === '';
    }

    public static function uuid(): string
    {
        $d = random_bytes(16);
        $d[6] = chr(ord($d[6]) & 0x0f | 0x40);
        $d[8] = chr(ord($d[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($d), 4));
    }

    /** @return array{id:string,status:string,confirmation_url:?string} */
    public static function createPayment(float $amount, string $description, string $idemKey, array $meta): array
    {
        if (self::isMock()) {
            return ['id' => 'mock-' . substr(str_replace('-', '', $idemKey), 0, 20), 'status' => 'pending', 'confirmation_url' => null];
        }
        $r = self::call('POST', '/payments', [
            'amount' => ['value' => number_format($amount, 2, '.', ''), 'currency' => 'RUB'],
            'capture' => true,
            'confirmation' => ['type' => 'redirect', 'return_url' => cfg('yookassa.return_url')],
            'description' => mb_substr($description, 0, 128),
            'metadata' => $meta,
        ], $idemKey);
        return ['id' => $r['id'], 'status' => $r['status'], 'confirmation_url' => $r['confirmation']['confirmation_url'] ?? null];
    }

    /** @return array{id:string,status:string} */
    public static function createRefund(string $paymentExtId, float $amount, string $idemKey): array
    {
        if (self::isMock()) {
            return ['id' => 'mock-refund-' . substr(str_replace('-', '', $idemKey), 0, 14), 'status' => 'succeeded'];
        }
        $r = self::call('POST', '/refunds', [
            'payment_id' => $paymentExtId,
            'amount' => ['value' => number_format($amount, 2, '.', ''), 'currency' => 'RUB'],
        ], $idemKey);
        return ['id' => $r['id'], 'status' => $r['status']];
    }

    /** Актуальный статус платежа (используется при обработке webhook для проверки подлинности). */
    public static function fetchPayment(string $extId): array
    {
        return self::call('GET', '/payments/' . rawurlencode($extId));
    }

    private static function call(string $method, string $path, ?array $body = null, ?string $idem = null): array
    {
        $ch = curl_init('https://api.yookassa.ru/v3' . $path);
        $headers = ['Content-Type: application/json'];
        if ($idem) {
            $headers[] = 'Idempotence-Key: ' . $idem;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_USERPWD => cfg('yookassa.shop_id') . ':' . cfg('yookassa.secret_key'),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $res = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $data = is_string($res) ? json_decode($res, true) : null;
        if ($code >= 400 || !is_array($data)) {
            throw new HttpException(502, 'Платёжный сервис недоступен или отклонил запрос');
        }
        return $data;
    }
}
