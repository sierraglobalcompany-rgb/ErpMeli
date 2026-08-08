<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Env;

final class WebhookController
{
    public function index():void
    {
        Auth::requireRole('admin','operador');
        $target = Auth::role() === 'admin' && !Auth::isTemporary()
            ? '/notifications/technical/events'
            : '/notifications/health';
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $target);
        exit;
    }
}
