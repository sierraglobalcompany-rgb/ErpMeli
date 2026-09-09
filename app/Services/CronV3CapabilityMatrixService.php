<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

/**
 * Matriz única de capacidad V3.
 *
 * La intención de esta clase es deliberadamente conservadora: una cola solo se
 * declara "v3_active" cuando existe un camino completo conocido. Si falta el
 * productor/importador o el cierre exacto de la fuente legacy, se marca como
 * waiting_capability para que el panel no vuelva a fingir que V3 la está
 * drenando.
 */
final class CronV3CapabilityMatrixService
{
    /** @var array<string,array<string,mixed>> */
    private const CAPABILITIES = [
        'notification_spool' => [
            'label' => 'Webhooks locales',
            'family' => 'notifications',
            'lane' => 'local',
            'state' => 'v3_local_only',
            'reason' => 'V3 local mueve el spool validado a eventos locales sin consultar Mercado Libre.',
            'work_types' => ['notification_spool'],
        ],
        'notification_normalize' => [
            'label' => 'Normalización de notificaciones',
            'family' => 'notifications',
            'lane' => 'local',
            'state' => 'v3_local_only',
            'reason' => 'V3 local convierte eventos validados en trabajos exactos FIFO; lo incompleto queda parqueado por identidad.',
            'work_types' => ['notification_normalize'],
        ],
        'notification_fallback' => [
            'label' => 'Ventas y notificaciones antiguas',
            'family' => 'notifications',
            'lane' => 'remote',
            'state' => 'v3_active',
            'reason' => 'V3 local importa recursos legacy con identidad segura como trabajos exactos; los registros sin evidencia quedan en diagnóstico local.',
            'work_types' => ['order_exact', 'pack_exact', 'shipment_exact', 'question_exact', 'claim_exact', 'item_exact'],
        ],
        'notification_backfill' => [
            'label' => 'Backfill de notificaciones',
            'family' => 'notifications',
            'lane' => 'local',
            'state' => 'v3_local_only',
            'reason' => 'V3 local ejecuta backfill acotado y checkpointed sin crear campañas persistentes.',
            'work_types' => ['notification_backfill'],
        ],
        'notification_identity_repair' => [
            'label' => 'Identidad de notificaciones',
            'family' => 'notifications',
            'lane' => 'local',
            'state' => 'legacy_readonly_backlog',
            'reason' => 'Registros parqueados por falta de identidad segura; se diagnostican localmente y no bloquean FIFO.',
            'work_types' => ['notification_identity_repair'],
        ],
        'recurring_sync' => [
            'label' => 'Programación recurrente',
            'family' => 'scheduler',
            'lane' => 'local',
            'state' => 'v3_local_only',
            'reason' => 'V3 local prepara rangos recurrentes con el servicio existente y ownership V3.',
            'work_types' => ['recurring_schedule'],
        ],
        'orders_sync' => [
            'label' => 'Sincronización de órdenes',
            'family' => 'orders',
            'lane' => 'remote',
            'state' => 'v3_active',
            'reason' => 'V3 local importa chunks legacy como páginas exactas y V3 remoto ejecuta orders_search_page/order_exact.',
            'work_types' => ['orders_search_page', 'order_exact'],
        ],
        'order_enrichment' => [
            'label' => 'Enriquecimiento de órdenes',
            'family' => 'orders',
            'lane' => 'remote',
            'state' => 'v3_active',
            'reason' => 'Pack y shipment exactos tienen importador legacy, handler y endpoint confirmado.',
            'work_types' => ['pack_exact', 'shipment_exact'],
        ],
        'sale_pack_reconciliation' => [
            'label' => 'Reconstrucción de packs',
            'family' => 'orders',
            'lane' => 'remote',
            'state' => 'v3_active',
            'reason' => 'V3 importa trabajos legacy de reconstrucción y ejecuta un job exacto por intento remoto.',
            'work_types' => ['sale_pack_reconciliation_exact'],
        ],
        'questions' => [
            'label' => 'Preguntas',
            'family' => 'attention',
            'lane' => 'remote',
            'state' => 'v3_active',
            'reason' => 'Búsqueda y exacto de preguntas tienen handlers confirmados de solo lectura.',
            'work_types' => ['questions_search_page', 'question_exact'],
        ],
        'claims' => [
            'label' => 'Reclamos',
            'family' => 'attention',
            'lane' => 'remote',
            'state' => 'v3_active',
            'reason' => 'Búsqueda y exacto de reclamos tienen handlers confirmados de solo lectura.',
            'work_types' => ['claims_search_page', 'claim_exact'],
        ],
        'financial_recalc' => [
            'label' => 'Recálculo financiero',
            'family' => 'finance',
            'lane' => 'local',
            'state' => 'v3_local_only',
            'reason' => 'Trabajo local certificado; no consulta Mercado Libre.',
            'work_types' => ['financial_local_projection', 'financial_recalc', 'financial_gap_scan'],
        ],
        'sale_financial_reconciliation' => [
            'label' => 'Conciliación financiera',
            'family' => 'finance',
            'lane' => 'remote',
            'state' => 'v3_active',
            'reason' => 'Captura Billing exacta por venta/input_version con un order_id por llamada física.',
            'work_types' => ['sale_billing_capture'],
        ],
        'financial_period_history' => [
            'label' => 'Histórico financiero por período',
            'family' => 'finance',
            'lane' => 'remote',
            'state' => 'waiting_capability',
            'reason' => 'Queda pendiente hasta certificar productor, endpoint y persistencia exacta por período sin reescribir cierres.',
            'work_types' => ['financial_period_history'],
        ],
        'sales_repair' => [
            'label' => 'Reparación de ventas',
            'family' => 'sales',
            'lane' => 'remote',
            'state' => 'v3_active',
            'reason' => 'V3 ejecuta reparación exacta de auditoría un ítem por intento y conserva fencing del job.',
            'work_types' => ['sales_repair_exact'],
        ],
        'sales_audit' => [
            'label' => 'Auditoría de ventas',
            'family' => 'sales',
            'lane' => 'remote',
            'state' => 'v3_active',
            'reason' => 'V3 ejecuta una página auditada por intento remoto y cierra el job exacto.',
            'work_types' => ['sales_audit_page'],
        ],
        'sales_fiscal' => [
            'label' => 'Preparación fiscal',
            'family' => 'sales',
            'lane' => 'remote',
            'state' => 'review_unsupported',
            'reason' => 'Endpoint/contrato exacto no certificado para ejecución automática V3.',
            'work_types' => ['sales_fiscal_exact'],
        ],
        'catalog_descriptions' => [
            'label' => 'Descripciones de productos',
            'family' => 'catalog',
            'lane' => 'remote',
            'state' => 'v3_active',
            'reason' => 'V3 local importa ítems de descripción pendientes como catalog_description_exact.',
            'work_types' => ['catalog_description_exact'],
        ],
        'items_sync' => [
            'label' => 'Productos Mercado Libre',
            'family' => 'catalog',
            'lane' => 'remote',
            'state' => 'v3_active',
            'reason' => 'V3 local importa trabajos de publicaciones como items_search_page/item_exact.',
            'work_types' => ['items_search_page', 'item_exact'],
        ],
        'module_jobs' => [
            'label' => 'Módulos',
            'family' => 'modules',
            'lane' => 'remote',
            'state' => 'review_unsupported',
            'reason' => 'Solo se permite module_logistics_exact cuando proveedor, tópico y endpoint estén confirmados.',
            'work_types' => ['module_logistics_exact'],
        ],
        'operational_maintenance' => [
            'label' => 'Mantenimiento local',
            'family' => 'maintenance',
            'lane' => 'local',
            'state' => 'v3_local_only',
            'reason' => 'Launcher V3 local toma lock/freeze antes de ejecutar mantenimiento técnico acotado.',
            'work_types' => ['operational_maintenance', 'monthly_report_maintenance'],
        ],
        'manual_campaign' => [
            'label' => 'Campaña dirigida / Procesar ahora',
            'family' => 'manual',
            'lane' => 'local',
            'state' => 'legacy_readonly_backlog',
            'reason' => 'Campañas heredadas quedan como diagnóstico; V3 no recrea ni continúa campañas persistentes.',
            'work_types' => [],
        ],
    ];

    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    /** @return array<string,array<string,mixed>> */
    public function matrix(): array
    {
        $pdo = $this->pdo ?? Database::connectionFresh();
        $rows = $this->capabilities($pdo);
        $ownership = $this->ownership($pdo);
        $workCounts = $this->workCounts($pdo);

        foreach ($rows as $queueKey => $row) {
            $workTypes = array_values((array) ($row['work_types'] ?? []));
            $owned = [];
            $ready = 0;
            $deferred = 0;
            $review = 0;
            $dead = 0;
            foreach ($workTypes as $type) {
                if (isset($ownership[$type])) {
                    $owned[$type] = $ownership[$type];
                }
                $ready += (int) ($workCounts[$type]['ready'] ?? 0);
                $deferred += (int) ($workCounts[$type]['deferred'] ?? 0);
                $review += (int) ($workCounts[$type]['review'] ?? 0);
                $dead += (int) ($workCounts[$type]['dead'] ?? 0);
            }

            $state = (string) $row['state'];
            $runtimeState = $this->runtimeState($state, $owned, $workTypes);
            $rows[$queueKey] = $row + [
                'queue_key' => $queueKey,
                'capability_state' => $state,
                'runtime_state' => $runtimeState,
                'owned_work_types' => $owned,
                'v3_ready' => $ready,
                'v3_deferred' => $deferred,
                'v3_review' => $review,
                'v3_dead' => $dead,
                'human_state' => $this->humanState($state, $runtimeState),
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    public function transferableWorkTypes(): array
    {
        $types = [];
        foreach ($this->capabilitiesForRuntime() as $row) {
            if (!in_array((string) $row['state'], ['v3_active', 'v3_local_only'], true)) {
                continue;
            }
            foreach ((array) ($row['work_types'] ?? []) as $type) {
                $types[(string) $type] = true;
            }
        }

        return array_keys($types);
    }

    /** @return list<string> */
    public function blockedWorkTypes(): array
    {
        $types = [];
        $transferable = array_fill_keys($this->transferableWorkTypes(), true);
        foreach ($this->capabilitiesForRuntime() as $row) {
            if (in_array((string) $row['state'], ['v3_active', 'v3_local_only'], true)) {
                continue;
            }
            foreach ((array) ($row['work_types'] ?? []) as $type) {
                $type = (string) $type;
                if (!isset($transferable[$type])) {
                    $types[$type] = true;
                }
            }
        }

        return array_keys($types);
    }

    /** @return array<string,array<string,mixed>> */
    private function capabilitiesForRuntime(): array
    {
        try {
            return $this->capabilities($this->pdo ?? Database::connectionFresh());
        } catch (Throwable) {
            return self::CAPABILITIES;
        }
    }

    /** @return array<string,array<string,mixed>> */
    private function capabilities(PDO $pdo): array
    {
        $rows = self::CAPABILITIES;
        try {
            $dbRows = $pdo->query(
                'SELECT queue_key,family,lane,capability_state,work_types_json,reason,updated_at
                 FROM cron_v3_capability_matrix'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return $rows;
        }

        foreach ($dbRows as $row) {
            $queueKey = (string) ($row['queue_key'] ?? '');
            if ($queueKey === '') {
                continue;
            }
            $decoded = json_decode((string) ($row['work_types_json'] ?? '[]'), true);
            $workTypes = is_array($decoded)
                ? array_filter(array_map('strval', $decoded))
                : [];
            $rows[$queueKey] = [
                'label' => (string) ($rows[$queueKey]['label'] ?? $queueKey),
                'family' => (string) ($row['family'] ?? ($rows[$queueKey]['family'] ?? 'unknown')),
                'lane' => (string) ($row['lane'] ?? ($rows[$queueKey]['lane'] ?? 'local')),
                'state' => (string) ($row['capability_state'] ?? ($rows[$queueKey]['state'] ?? 'waiting_capability')),
                'reason' => (string) ($row['reason'] ?? ($rows[$queueKey]['reason'] ?? 'Capacidad V3 por comprobar.')),
                'work_types' => $workTypes !== [] ? $workTypes : array_values((array) ($rows[$queueKey]['work_types'] ?? [])),
                'updated_at' => $row['updated_at'] ?? null,
                'source' => 'database',
            ];
        }

        return $rows;
    }

    /** @return array<string,array<string,mixed>> */
    private function ownership(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                'SELECT queue_key,lane,owner_engine,enabled,changed_by,changed_at
                 FROM cron_v3_queue_ownership'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['queue_key']] = [
                'lane' => (string) $row['lane'],
                'owner_engine' => (string) $row['owner_engine'],
                'enabled' => (int) ($row['enabled'] ?? 0) === 1,
                'changed_by' => $row['changed_by'] ?? null,
                'changed_at' => $row['changed_at'] ?? null,
            ];
        }

        return $result;
    }

    /** @return array<string,array<string,int>> */
    private function workCounts(PDO $pdo): array
    {
        try {
            $rows = $pdo->query(
                'SELECT work_type,status,COUNT(*) total FROM cron_v3_work GROUP BY work_type,status'
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
        $result = [];
        foreach ($rows as $row) {
            $type = (string) $row['work_type'];
            $status = (string) $row['status'];
            $result[$type][$status] = (int) $row['total'];
        }

        return $result;
    }

    /** @param array<string,array<string,mixed>> $owned @param list<string> $workTypes */
    private function runtimeState(string $state, array $owned, array $workTypes): string
    {
        if (!in_array($state, ['v3_active', 'v3_local_only'], true)) {
            return $state;
        }
        foreach ($workTypes as $type) {
            if (($owned[$type]['owner_engine'] ?? null) === 'v3' && !empty($owned[$type]['enabled'])) {
                return 'owned_by_v3';
            }
        }

        return 'missing_ownership';
    }

    private function humanState(string $state, string $runtimeState): string
    {
        return match ($runtimeState) {
            'owned_by_v3' => $state === 'v3_local_only' ? 'V3 local activo' : 'V3 activo',
            'missing_ownership' => 'Capacidad lista, falta ownership V3',
            'waiting_capability' => 'Esperando capacidad V3',
            'review_unsupported' => 'No soportado automáticamente',
            'legacy_readonly_backlog' => 'Histórico de solo diagnóstico',
            default => 'Por comprobar',
        };
    }
}
