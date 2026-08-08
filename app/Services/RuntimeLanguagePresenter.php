<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Vocabulario único para que las pantallas no vuelvan a sugerir segundos
 * workers, Cron paralelo o colas invisibles donde el trabajo es local.
 */
final class RuntimeLanguagePresenter
{
    public static function singleLauncher(): string
    {
        return 'lanzador único del ERP';
    }

    public static function hostingerCron(): string
    {
        return 'Cron de Hostinger';
    }

    public static function browserBackup(): string
    {
        return 'Esta pestaña crea la copia por micro-lotes locales.';
    }

    public static function browserMaintenance(): string
    {
        return 'Esta pestaña ejecuta micro-lotes locales de mantenimiento.';
    }

    public static function noRemoteTransport(): string
    {
        return 'No se consultará Mercado Libre.';
    }

    /** @return list<string> */
    public static function browserForbiddenWords(): array
    {
        return ['Esperando lanzador', 'worker', 'cron', 'en cola'];
    }

    /** @return list<string> */
    public static function operationalCronStates(): array
    {
        return [
            'ready',
            'waiting_automation',
            'waiting_api',
            'waiting_budget',
            'waiting_lock',
            'waiting_schedule',
            'action_required',
            'empty',
            'failed',
        ];
    }
}
