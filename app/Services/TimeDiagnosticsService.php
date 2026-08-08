<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

final class TimeDiagnosticsService
{
    /**
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        $erpTimezone = DateTimePresenter::timezone();
        $phpTimezone = date_default_timezone_get();
        $phpNow = new DateTimeImmutable('now');
        $phpUtc = $phpNow->setTimezone(new DateTimeZone('UTC'));
        $phpLocal = $phpNow->setTimezone(new DateTimeZone($erpTimezone));
        $mysqlNow = null;
        $mysqlUtc = null;
        $mysqlTimezone = null;
        $diffSeconds = null;
        $warnings = [];
        $notices = [];

        try {
            $row = Database::connection()->query('SELECT NOW() mysql_now, UTC_TIMESTAMP() mysql_utc, @@session.time_zone session_tz, @@global.time_zone global_tz')
                ->fetch(PDO::FETCH_ASSOC) ?: [];
            $mysqlNow = (string) ($row['mysql_now'] ?? '');
            $mysqlUtc = (string) ($row['mysql_utc'] ?? '');
            $mysqlTimezone = trim((string) ($row['session_tz'] ?? '')) ?: (string) ($row['global_tz'] ?? '');
            if ($mysqlUtc !== '') {
                $mysqlUtcDt = new DateTimeImmutable($mysqlUtc, new DateTimeZone('UTC'));
                $diffSeconds = abs($mysqlUtcDt->getTimestamp() - $phpUtc->getTimestamp());
                if ($diffSeconds > 120) {
                    $warnings[] = 'La hora UTC de MySQL difiere de PHP por más de 2 minutos.';
                }
            }
            if ($mysqlNow !== '' && $mysqlUtc !== '' && $mysqlNow !== $mysqlUtc) {
                $warnings[] = 'MySQL NOW() no coincide con UTC_TIMESTAMP(); por eso las colas deben usar UTC_TIMESTAMP().';
            }
        } catch (Throwable $e) {
            $warnings[] = UiLabelPresenter::safeOperationMessage(
                $e->getMessage(),
                SafeErrorPresenter::report($e, 'No se pudo comprobar la hora de la base de datos.')['reference']
            );
        }

        if ($phpTimezone !== 'UTC') {
            $notices[] = 'PHP usa ' . $phpTimezone . '; el ERP guarda las colas en UTC y presenta las fechas usando ' . $erpTimezone . '.';
        }

        return [
            'erp_timezone' => $erpTimezone,
            'php_timezone' => $phpTimezone,
            'php_now' => $phpNow->format('Y-m-d H:i:s T'),
            'php_utc' => $phpUtc->format('Y-m-d H:i:s'),
            'php_local' => $phpLocal->format('Y-m-d H:i:s'),
            'mysql_now' => $mysqlNow ?: '—',
            'mysql_utc' => $mysqlUtc ?: '—',
            'mysql_timezone' => $mysqlTimezone ?: '—',
            'mysql_php_utc_diff_seconds' => $diffSeconds,
            'warnings' => $warnings,
            'notices' => $notices,
            'ok' => $warnings === [],
        ];
    }
}
