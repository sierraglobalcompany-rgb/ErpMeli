<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

final class SystemSafetyStatusService
{
    /** @return array<string,mixed> */
    public function status(): array
    {
        $status = (new EmergencyControlService())->status();
        $status['writes'] = Env::bool('ML_WRITE_ENABLED', false) ? 'enabled' : 'disabled';
        $status['safe'] = $status['writes'] === 'disabled'
            && $status['api'] === 'stopped'
            && $status['automation'] === 'stopped';
        $status['label'] = match (true) {
            $status['writes'] === 'enabled' => 'Escrituras remotas habilitadas',
            $status['api'] === 'stopped' && $status['automation'] === 'stopped' => 'Freno de mano activo',
            $status['api'] === 'stopped' => 'Mercado Libre bloqueado',
            $status['automation'] === 'stopped' => 'Automatización detenida',
            $status['api'] === 'canary' => 'Mercado Libre en prueba canaria',
            default => 'Escrituras remotas deshabilitadas',
        };
        return $status;
    }
}
