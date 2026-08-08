<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Crypto
{
    public static function encrypt(string $plain): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('No fue posible cifrar el secreto.');
        }
        return base64_encode("v1" . $iv . $tag . $cipher);
    }

    public static function decrypt(string $encoded): string
    {
        $payload = base64_decode($encoded, true);
        if ($payload === false || strlen($payload) < 30 || substr($payload, 0, 2) !== 'v1') {
            throw new RuntimeException('Secreto cifrado inválido.');
        }
        $iv = substr($payload, 2, 12);
        $tag = substr($payload, 14, 16);
        $plain = openssl_decrypt(substr($payload, 30), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('No fue posible descifrar el secreto.');
        }
        return $plain;
    }

    private static function key(): string
    {
        $configured = Env::get('APP_KEY', '');
        if ($configured === '') {
            throw new RuntimeException('APP_KEY no está configurada.');
        }
        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);
            if ($decoded === false || strlen($decoded) < 32) {
                throw new RuntimeException('APP_KEY base64 inválida.');
            }
            return substr($decoded, 0, 32);
        }
        return hash('sha256', $configured, true);
    }
}
