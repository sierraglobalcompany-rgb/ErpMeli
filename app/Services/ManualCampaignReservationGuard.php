<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Evita que el cron normal reclame un recurso congelado por una campaña.
 * La propia campaña conserva acceso porque ya posee el lease cercado del ítem.
 */
final class ManualCampaignReservationGuard
{
    public static function sql(string $queueKey, string $idExpression): string
    {
        if ((ApiExecutionMetadataContext::current()['source'] ?? '') === 'manual_campaign') {
            return '';
        }
        if (preg_match('/^[a-zA-Z0-9_.]+$/', $idExpression) !== 1) {
            throw new \InvalidArgumentException('Expresión de reserva no válida.');
        }
        $queue = str_replace("'", "''", $queueKey);
        return ' AND NOT EXISTS (
            SELECT 1 FROM manual_campaign_reservations mcr
            JOIN manual_campaigns mc ON mc.id=mcr.manual_campaign_id
            WHERE mcr.queue_key=\'' . $queue . '\'
              AND mcr.source_id=CAST(' . $idExpression . ' AS CHAR)
              AND mcr.status="active"
              AND mcr.expires_at>UTC_TIMESTAMP(3)
              AND mc.status IN ("active","pausing","paused")
        )';
    }
}
