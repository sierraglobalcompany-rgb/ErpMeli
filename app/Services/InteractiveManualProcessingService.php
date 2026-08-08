<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Compatibilidad retirada del antiguo ejecutor web.
 *
 * Las rutas HTTP heredadas responden 410 desde SettingsController. Esta clase
 * permanece únicamente para que extensiones antiguas fallen de forma segura:
 * no abre MariaDB, no adquiere recursos y no puede alcanzar un adaptador ni el
 * transporte de Mercado Libre.
 */
final class InteractiveManualProcessingService
{
    /** @return never */
    public function heartbeat(int $campaignId, int $userId, string $browserOwner, bool $release = false): array
    {
        throw new RuntimeException(
            'El control interactivo fue retirado. Las campañas continúan exclusivamente mediante el lanzador CLI.'
        );
    }

    /** @return never */
    public function step(
        int $campaignId,
        int $userId,
        string $browserOwner,
        string $clientStepKey
    ): array {
        throw new RuntimeException(
            'El procesamiento web fue retirado. Ninguna petición HTTP puede ejecutar trabajos o consultar Mercado Libre.'
        );
    }
}
