<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

/**
 * Promueve únicamente las familias V3 ya certificadas después de un canario
 * sano. No ejecuta handlers, no consulta Mercado Libre y no toca datos
 * comerciales: solo cambia ownership técnico para que V2 deje de competir en
 * esas familias.
 */
final class CronV3CertifiedCutoverService
{
    /** @var list<string> */
    private const BASE_CANARY_TYPES = ['financial_recalc', 'pack_exact', 'shipment_exact'];

    /** @var list<string> */
    private const CERTIFIED_TYPES = ['financial_recalc', 'pack_exact', 'shipment_exact', 'sale_billing_capture'];

    private const MIN_CANARY_HTTP = 25;

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    /** @return array<string,mixed> */
    public function snapshot(): array
    {
        $pdo = $this->connection();
        if (!$this->hasSchema($pdo)) {
            return [
                'available' => false,
                'enabled' => false,
                'state' => 'schema_unavailable',
                'label' => 'Corte certificado no disponible',
                'owned' => [],
                'missing' => self::CERTIFIED_TYPES,
                'blocking' => ['Esquema Cron V3 incompleto.'],
            ];
        }

        $ownership = $this->ownership($pdo, self::CERTIFIED_TYPES);
        $metrics = $this->metrics($pdo, self::BASE_CANARY_TYPES);
        $blocking = $this->blockingReasons($ownership, $metrics);
        $missing = [];
        foreach (self::CERTIFIED_TYPES as $type) {
            if (($ownership[$type]['owner_engine'] ?? null) !== 'v3' || empty($ownership[$type]['enabled'])) {
                $missing[] = $type;
            }
        }

        return [
            'available' => true,
            'enabled' => $this->settingBool('cron_v3.certified_cutover.enabled', true),
            'state' => $blocking === []
                ? ($missing === [] ? 'certified_active' : 'ready_to_cutover')
                : 'blocked',
            'label' => $blocking === []
                ? ($missing === [] ? 'Familias certificadas en V3' : 'Listo para corte certificado')
                : 'Corte certificado bloqueado',
            'owned' => $ownership,
            'missing' => $missing,
            'metrics' => $metrics,
            'blocking' => $blocking,
            'certified_types' => self::CERTIFIED_TYPES,
        ];
    }

    /** @return array<string,mixed> */
    public function applyIfSafe(string $changedBy = 'auto_cli'): array
    {
        $pdo = $this->connection();
        if (!$this->settingBool('cron_v3.certified_cutover.enabled', true)) {
            return ['ok' => true, 'applied' => false, 'reason' => 'disabled_by_setting'];
        }

        $snapshot = $this->snapshot();
        if (($snapshot['state'] ?? '') === 'certified_active') {
            return ['ok' => true, 'applied' => false, 'reason' => 'already_active'];
        }
        if (($snapshot['state'] ?? '') !== 'ready_to_cutover') {
            return ['ok' => false, 'applied' => false, 'reason' => 'blocked', 'blocking' => $snapshot['blocking'] ?? []];
        }

        $pdo->beginTransaction();
        try {
            foreach (self::CERTIFIED_TYPES as $type) {
                $lane = $type === 'financial_recalc' ? 'local' : 'remote';
                $stmt = $pdo->prepare(
                    "INSERT INTO cron_v3_queue_ownership (queue_key,lane,owner_engine,enabled,changed_by,changed_at)
                     VALUES (:queue_key,:lane,'v3',1,:changed_by,UTC_TIMESTAMP(3))
                     ON DUPLICATE KEY UPDATE
                        lane=VALUES(lane),
                        owner_engine='v3',
                        enabled=1,
                        changed_by=VALUES(changed_by),
                        changed_at=VALUES(changed_at)"
                );
                $stmt->execute([
                    'queue_key' => $type,
                    'lane' => $lane,
                    'changed_by' => 'certified_cutover_2_29_12:' . $changedBy,
                ]);
            }
            $this->upsertSetting($pdo, 'cron_v3.certified_cutover.phase', 'active');
            $this->upsertSetting($pdo, 'cron_v3.certified_cutover.last_changed_at', gmdate('Y-m-d H:i:s'));
            $pdo->commit();
            return ['ok' => true, 'applied' => true, 'reason' => 'certified_cutover_applied'];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'applied' => false, 'reason' => 'cutover_failed'];
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function ownership(PDO $pdo, array $types): array
    {
        $marks = implode(',', array_fill(0, count($types), '?'));
        $stmt = $pdo->prepare(
            'SELECT queue_key,lane,owner_engine,enabled,changed_by,changed_at
             FROM cron_v3_queue_ownership
             WHERE queue_key IN (' . $marks . ')
             ORDER BY lane,queue_key'
        );
        $stmt->execute($types);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['queue_key']] = [
                'lane' => (string) $row['lane'],
                'owner_engine' => (string) $row['owner_engine'],
                'enabled' => (int) $row['enabled'] === 1,
                'changed_by' => $row['changed_by'] ?? null,
                'changed_at' => $row['changed_at'] ?? null,
            ];
        }
        return $result;
    }

    /** @return array<string,int> */
    private function metrics(PDO $pdo, array $types): array
    {
        $marks = implode(',', array_fill(0, count($types), '?'));
        $stmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(a.physical_http_calls),0) AS http_calls,
                COALESCE(SUM(a.known_response_count),0) AS known_responses,
                SUM(CASE WHEN a.outcome='completed' THEN 1 ELSE 0 END) AS completed,
                SUM(CASE WHEN a.outcome IN ('review','dead') THEN 1 ELSE 0 END) AS errors,
                SUM(CASE WHEN a.outcome='lease_lost' THEN 1 ELSE 0 END) AS lease_lost
             FROM cron_v3_attempts a
             INNER JOIN cron_v3_work w ON w.id=a.work_id
             WHERE w.work_type IN (" . $marks . ")
               AND a.started_at >= (UTC_TIMESTAMP() - INTERVAL 24 HOUR)"
        );
        $stmt->execute($types);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'http_calls' => (int) ($row['http_calls'] ?? 0),
            'known_responses' => (int) ($row['known_responses'] ?? 0),
            'completed' => (int) ($row['completed'] ?? 0),
            'errors' => (int) ($row['errors'] ?? 0),
            'lease_lost' => (int) ($row['lease_lost'] ?? 0),
        ];
    }

    /** @param array<string,array<string,mixed>> $ownership @param array<string,int> $metrics @return list<string> */
    private function blockingReasons(array $ownership, array $metrics): array
    {
        $blocking = [];
        if (!Env::bool('CRON_V3_ENABLED', false)) {
            $blocking[] = 'CRON_V3_ENABLED debe estar activo para aplicar corte certificado.';
        }
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            $blocking[] = 'ML_WRITE_ENABLED debe permanecer en false.';
        }
        foreach (self::BASE_CANARY_TYPES as $type) {
            if (($ownership[$type]['owner_engine'] ?? null) !== 'v3' || empty($ownership[$type]['enabled'])) {
                $blocking[] = 'Falta ownership sano del canario base: ' . $type . '.';
            }
        }
        if ($metrics['http_calls'] < self::MIN_CANARY_HTTP) {
            $blocking[] = 'Falta evidencia HTTP del canario base: ' . $metrics['http_calls'] . '/' . self::MIN_CANARY_HTTP . '.';
        }
        if ($metrics['errors'] > 0) {
            $blocking[] = 'El canario base tiene errores recientes.';
        }
        if ($metrics['lease_lost'] > 0) {
            $blocking[] = 'El canario base perdió leases recientes.';
        }
        return $blocking;
    }

    private function hasSchema(PDO $pdo): bool
    {
        foreach (['cron_v3_work', 'cron_v3_attempts', 'cron_v3_queue_ownership'] as $table) {
            try {
                $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
            } catch (Throwable) {
                return false;
            }
        }
        return true;
    }

    private function settingBool(string $key, bool $default): bool
    {
        try {
            $stmt = $this->connection()->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
            $stmt->execute([$key]);
            $value = $stmt->fetchColumn();
            if ($value === false) {
                return $default;
            }
            return filter_var((string) $value, FILTER_VALIDATE_BOOL);
        } catch (Throwable) {
            return $default;
        }
    }

    private function upsertSetting(PDO $pdo, string $key, string $value): void
    {
        $pdo->prepare(
            'INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group)
             VALUES (?, ?, 0, "cron_v3")
             ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_encrypted=0,setting_group="cron_v3"'
        )->execute([$key, $value]);
    }

    private function connection(): PDO
    {
        return $this->pdo ?? Database::connectionFresh();
    }
}
