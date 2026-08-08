<?php

declare(strict_types=1);

namespace App\Services;

final class MonthlyReportStateMachine
{
    private const TRANSITIONS = [
        'borrador' => ['revisado', 'anulado'],
        'revisado' => ['borrador', 'aprobado', 'anulado'],
        'aprobado' => ['facturado', 'anulado'],
        'facturado' => [],
        'anulado' => [],
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }
}
