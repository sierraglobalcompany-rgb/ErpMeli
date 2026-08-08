<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/**
 * La telemetría histórica de Cron es global porque sus ejecuciones antiguas
 * no contienen un scope tenant completo. Solo puede verla un administrador
 * con concesión explícita sobre todas las empresas activas.
 */
final class CronOperationalAccessScope
{
    public function assertGlobal(): void
    {
        $scope = (new ApiHealthAccessScope())->snapshot();
        if (empty($scope['application'])) {
            throw new HttpException(404, 'No se encontró el recurso solicitado.');
        }
    }
}
