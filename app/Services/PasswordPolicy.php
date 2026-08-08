<?php

declare(strict_types=1);

namespace App\Services;

final class PasswordPolicy
{
    public const MESSAGE = 'La contraseña debe tener mínimo 6 caracteres e incluir una mayúscula, un número y un carácter especial.';

    public static function isValid(string $password): bool
    {
        return preg_match('/^.{6,}$/us', $password) === 1
            && preg_match('/\p{Lu}/u', $password) === 1
            && preg_match('/\p{N}/u', $password) === 1
            && preg_match('/[^\p{L}\p{N}\s]/u', $password) === 1;
    }
}
