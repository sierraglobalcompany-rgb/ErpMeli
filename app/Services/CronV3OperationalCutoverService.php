<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

/**
 * Corte operativo V3: V3 queda como única autoridad automática. Los trabajos
 * que aún no tienen contrato seguro quedan visibles como bloqueados, no vuelven
 * silenciosamente a V2.
 */
final class CronV3OperationalCutoverService
{
    /** @var list<string> */
    public const TRANSFERABLE_TYPES = [
        'oauth_refresh',
        'notification_spool',
        'notification_normalize',
        'notification_backfill',
        'recurring_schedule',
        'claims_search_page',
        'claim_exact',
        'orders_search_page',
        'order_exact',
        'pack_exact',
        'shipment_exact',
        'sale_pack_reconciliation_exact',
        'questions_search_page',
        'question_exact',
        'items_search_page',
        'item_exact',
        'catalog_description_exact',
        'module_logistics_exact',
        'financial_local_projection',
        'financial_recalc',
        'financial_gap_scan',
        'sale_billing_capture',
        'sales_audit_page',
        'sales_repair_exact',
        'cron_v3_health_snapshot',
        'operational_maintenance',
        'monthly_report_maintenance',
    ];

    /** @var list<string> */
    public const BLOCKED_TYPES = [
        'order_date_repair',
        'sales_fiscal_exact',
    ];

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
                'state' => 'schema_unavailable',
                'label' => 'Corte V3 no disponible',
                'blocking' => ['Esquema Cron V3 incompleto.'],
                'transferable_types' => self::TRANSFERABLE_TYPES,
                'blocked_types' => self::BLOCKED_TYPES,
            ];
        }

        $capability = new CronV3CapabilityMatrixService($pdo);
        $transferableTypes = $capability->transferableWorkTypes();
        $blockedTypes = $capability->blockedWorkTypes();
        $matrix = $capability->matrix();
        $ownership = $this->ownership($pdo, array_merge($transferableTypes, $blockedTypes));
        $missing = [];
        foreach ($transferableTypes as $type) {
            if (($ownership[$type]['owner_engine'] ?? null) !== 'v3' || empty($ownership[$type]['enabled'])) {
                $missing[] = $type;
            }
        }
        $blocking = $this->blockingReasons();

        return [
            'available' => true,
            'state' => $blocking !== [] ? 'blocked' : ($missing === [] ? 'operational_active' : 'ready_to_apply'),
            'label' => $blocking !== []
                ? 'Corte V3 bloqueado'
                : ($missing === [] ? 'V3 operativo' : 'Listo para corte V3'),
            'blocking' => $blocking,
            'missing_transferable' => $missing,
            'ownership' => $ownership,
            'transferable_types' => $transferableTypes,
            'blocked_types' => $blockedTypes,
            'capability_matrix' => $matrix,
        ];
    }

    /** @return array<string,mixed> */
    public function applyIfSafe(string $changedBy = 'auto_cli'): array
    {
        $snapshot = $this->snapshot();
        if (($snapshot['state'] ?? '') === 'operational_active') {
            return ['ok' => true, 'applied' => false, 'reason' => 'already_operational'];
        }
        if (($snapshot['state'] ?? '') !== 'ready_to_apply') {
            return ['ok' => false, 'applied' => false, 'reason' => 'blocked', 'blocking' => $snapshot['blocking'] ?? []];
        }

        $pdo = $this->connection();
        $capability = new CronV3CapabilityMatrixService($pdo);
        $transferableTypes = $capability->transferableWorkTypes();
        $blockedTypes = $capability->blockedWorkTypes();
        $pdo->beginTransaction();
        try {
            foreach ($transferableTypes as $type) {
                $this->setOwnership($pdo, $type, $this->laneFor($type), 'v3', true, 'operational_cutover_2_30_0:' . $changedBy);
            }
            foreach ($blockedTypes as $type) {
                $this->setOwnership($pdo, $type, $this->laneFor($type), 'disabled', false, 'v3_waiting_capability_2_31_0:' . $changedBy);
            }
            $this->upsertSetting($pdo, 'cron_v3.operational_phase', 'active');
            $this->upsertSetting($pdo, 'cron_v3.operational_last_changed_at', gmdate('Y-m-d H:i:s'));
            $pdo->commit();
            return ['ok' => true, 'applied' => true, 'reason' => 'operational_cutover_applied'];
        } catch (Throwable) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'applied' => false, 'reason' => 'operational_cutover_failed'];
        }
    }

    /** @return list<string> */
    private function blockingReasons(): array
    {
        $blocking = [];
        $mode = new CronV3OperationalModeService($this->connection());
        if (!$mode->enabled()) {
            $blocking[] = 'cron_v3.operational_mode debe estar activo y rollback apagado.';
        }
        if (!Env::bool('CRON_V3_ENABLED', false)) {
            $blocking[] = 'CRON_V3_ENABLED debe estar en true.';
        }
        if (Env::bool('CRON_V3_SHADOW_ENABLED', false)) {
            $blocking[] = 'CRON_V3_SHADOW_ENABLED debe estar en false para operación real.';
        }
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            $blocking[] = 'ML_WRITE_ENABLED debe permanecer en false.';
        }
        return $blocking;
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
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
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

    private function setOwnership(PDO $pdo, string $type, string $lane, string $owner, bool $enabled, string $changedBy): void
    {
        $pdo->prepare(
            "INSERT INTO cron_v3_queue_ownership (queue_key,lane,owner_engine,enabled,changed_by,changed_at)
             VALUES (:queue_key,:lane,:owner_engine,:enabled,:changed_by,UTC_TIMESTAMP(3))
             ON DUPLICATE KEY UPDATE
                lane=VALUES(lane),
                owner_engine=VALUES(owner_engine),
                enabled=VALUES(enabled),
                changed_by=VALUES(changed_by),
                changed_at=VALUES(changed_at)"
        )->execute([
            'queue_key' => $type,
            'lane' => $lane,
            'owner_engine' => $owner,
            'enabled' => $enabled ? 1 : 0,
            'changed_by' => $changedBy,
        ]);
    }

    private function laneFor(string $type): string
    {
        return (new CronV3WorkTypeRegistry())->laneFor($type);
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
