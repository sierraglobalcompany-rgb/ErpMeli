<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf');
        if (!is_string($token) || $token === '') {
            // Algunos GET pesados liberan el lock de sesión antes de renderizar.
            // Si una sesión heredada todavía no tiene token, hay que reabrirla y
            // persistirlo; escribir solamente sobre el snapshot de $_SESSION
            // produciría un formulario cuyo POST siempre sería rechazado.
            Session::start();
            $token = Session::get('_csrf');
        }
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::put('_csrf', $token);
        }
        return $token;
    }

    public static function validate(?string $token): void
    {
        $known = Session::get('_csrf');
        if (!is_string($token) || !is_string($known) || !hash_equals($known, $token)) {
            throw new HttpException(
                403,
                'La sesión de seguridad venció. Recargue la página e inténtelo nuevamente.'
            );
        }
    }
}
