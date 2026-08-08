<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Database;
use App\Core\HttpException;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

final class ImportedMeliDataResetService
{
    private ImportedMeliDataResetPolicy $policy;
    private InformationSchemaGateway $schema;

    public function __construct()
    {
        $this->policy = new ImportedMeliDataResetPolicy();
        $this->schema = new InformationSchemaGateway();
    }

    /** @return array<string,mixed> */
    public function overview(int $userId, int $requestId = 0): array
    {
        $request = $requestId > 0
            ? $this->request($requestId, $userId)
            : $this->latestRequest($userId);
        $safety = (new EmergencyControlService())->status();
        $accounts = $this->authorizedAccounts($userId);
        return [
            'request' => $request,
            'safety' => $safety,
            'accounts' => $accounts,
            'active_campaigns' => $this->activeCampaigns($accounts),
            'recent_steps' => $request === null ? [] : $this->recentSteps((int) $request['id']),
            // La pantalla solo hace una comprobación ligera. La autorización
            // vuelve a descifrar y verificar criptográficamente el archivo.
            'fresh_backup' => $request === null ? null : $this->freshVerifiedBackup($request, false),
            'preserved_labels' => [
                'Empresas, cuentas vinculadas y tokens OAuth',
                'Usuarios, permisos, configuración, claves y migraciones',
                'Productos internos, vínculos y catálogos configurados',
                'Reportes, cierres y evidencia fiscal inmutable',
                'Copias de seguridad y bitácora de esta operación',
            ],
            'deleted_labels' => [
                'Ventas y recursos importados que no sostienen evidencia',
                'Preguntas, reclamos, notificaciones y webhooks almacenados',
                'Publicaciones sin vínculos o catálogos creados por personas',
                'Historial técnico y trabajos derivados del contenido retirado',
            ],
            'reset_labels' => $this->policy->resetLabels(),
        ];
    }

    /**
     * Las campañas activas nunca son ruido ni se resuelven automáticamente.
     * Se muestran antes de autorizar el restablecimiento para que el
     * administrador decida conservarlas o devolver sus pendientes.
     *
     * @param list<array<string,mixed>> $accounts
     * @return list<array<string,mixed>>
     */
    private function activeCampaigns(array $accounts): array
    {
        if (!$this->schema->hasTable('manual_campaigns')
            || !$this->schema->hasTable('manual_campaign_items')) {
            return [];
        }
        $accountIds = array_values(array_unique(array_filter(array_map(
            static fn (array $account): int => (int) ($account['id'] ?? 0),
            $accounts
        ), static fn (int $id): bool => $id > 0)));
        if ($accountIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $stmt = Database::connection()->prepare(
            'SELECT c.id,c.status,c.total_items,c.total_units,
                    c.completed_items,c.returned_items,c.safe_message
             FROM manual_campaigns c
             WHERE c.status IN ("active","pausing","paused","finishing")
               AND EXISTS (
                   SELECT 1
                   FROM manual_campaign_items i
                   WHERE i.manual_campaign_id=c.id
                     AND i.meli_account_id IN (' . $placeholders . ')
               )
             ORDER BY c.id ASC'
        );
        $stmt->execute($accountIds);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'status' => (string) $row['status'],
            'total_items' => (int) $row['total_items'],
            'total_units' => (int) $row['total_units'],
            'completed_items' => (int) $row['completed_items'],
            'returned_items' => (int) $row['returned_items'],
            'safe_message' => (string) ($row['safe_message'] ?? ''),
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string,mixed> */
    public function analyze(int $userId): array
    {
        $active = (int) Database::connection()->query(
            'SELECT COUNT(*) FROM imported_data_reset_requests
             WHERE status IN ("authorized","running","pausing","paused","verifying")'
        )->fetchColumn();
        if ($active > 0) {
            throw new RuntimeException(
                'Ya existe un restablecimiento activo. Termine o cierre esa solicitud antes de analizar otra.'
            );
        }
        $inventory = $this->assertClassifiedInventory();
        $policyOperations = $this->policy->operations();
        $this->primeOperationSchema($policyOperations);
        $accounts = $this->authorizedAccounts($userId);
        if ($accounts === []) {
            throw new RuntimeException('No hay cuentas autorizadas para analizar.');
        }
        $accountIds = array_map(static fn (array $row): int => (int) $row['id'], $accounts);
        $operations = [];
        $total = 0;
        foreach ($policyOperations as $operation) {
            if ($this->schema->hasTable((string) $operation['table'])
                && !$this->operationAvailable($operation)) {
                throw new RuntimeException(
                    'La política no puede aislar por cuenta el conjunto ' . $operation['label'] . '.'
                );
            }
            $candidateState = $this->candidateState($operation, $accountIds);
            $count = $candidateState['count'];
            $operation['candidate_count'] = $count;
            $operation['candidate_sha256'] = $candidateState['sha256'];
            $operation['availability'] = $this->schema->hasTable((string) $operation['table'])
                ? 'available'
                : 'not_installed';
            $operations[] = $operation;
            $total += $count;
        }
        $integrity = $this->integritySnapshot();
        $publicId = $this->uuid();
        $challenge = strtoupper(substr(str_replace('-', '', $publicId), -6));
        $scope = [
            'mode' => 'all_authorized_accounts',
            'account_ids' => $accountIds,
            'accounts' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['account_name'],
                'company' => (string) $row['company_name'],
            ], $accounts),
        ];
        $plan = [
            'policy_version' => 2,
            'operations' => $operations,
            'candidate_total' => $total,
            'protected_tables' => $this->policy->protectedTables(),
            'conservative' => true,
            'remote_writes' => false,
            'inventory_sha256' => $inventory['sha256'],
            'inventory_table_count' => $inventory['count'],
        ];
        $stmt = Database::connection()->prepare(
            'INSERT INTO imported_data_reset_requests
             (public_id,requested_by,status,phase,scope_json,plan_json,counters_json,
              integrity_before_json,integrity_sha256,confirmation_challenge,safe_message)
             VALUES (:public_id,:user_id,"analyzed","analysis",:scope,:plan,:counters,
                     :integrity,:integrity_hash,:challenge,:message)'
        );
        $stmt->execute([
            'public_id' => $publicId,
            'user_id' => $userId,
            'scope' => $this->json($scope),
            'plan' => $this->json($plan),
            'counters' => $this->json($this->emptyCounters()),
            'integrity' => $this->json($integrity),
            'integrity_hash' => $this->hash($integrity),
            'challenge' => $challenge,
            'message' => $total > 0
                ? 'Análisis listo. Cree ahora una copia cifrada y verificada.'
                : 'No se encontraron datos importados que esta política pueda retirar.',
        ]);
        return $this->request((int) Database::connection()->lastInsertId(), $userId);
    }

    /** @return array<string,mixed> */
    public function authorize(
        int $requestId,
        int $userId,
        int|string $backupId,
        ?string $legacyChallenge = null,
        ?int $legacyBackupId = null
    ): array {
        unset($legacyChallenge);
        $backupId = $legacyBackupId ?? (int) $backupId;
        $pdo = Database::connection();
        $freeze = new DatabaseMutationFreezeService();
        $freezeOwner = 'request-' . $requestId;
        $freezeActivated = false;
        $pdo->beginTransaction();
        try {
            $request = $this->lockedRequest($requestId, $userId);
            if ((string) $request['status'] !== 'analyzed') {
                throw new RuntimeException('Este análisis ya no puede autorizarse.');
            }
            $plan = json_decode((string) $request['plan_json'], true);
            $this->assertInventoryHash((string) ($plan['inventory_sha256'] ?? ''));
            $backup = $this->freshVerifiedBackup($request, true, $backupId);
            if ($backup === null) {
                throw new RuntimeException(
                    'Cree y verifique una copia cifrada después del análisis antes de continuar.'
                );
            }
            // Rechazar primero cualquier consentimiento obsoleto. Así una
            // segunda pestaña o un análisis que cambió no deja API/cron
            // detenidos antes de saber que la solicitud sigue siendo válida.
            $this->assertAuthorizedScope($request, $userId);
            $this->assertCandidatePlanUnchanged($request);
            $safety = new EmergencyControlService();
            $safety->stopAll(
                'admin:' . $userId,
                'Restablecimiento autorizado de datos importados de Mercado Libre.'
            );
            // El freeze se activa antes de inspeccionar workers: cierra la
            // ventana en la que un proceso nuevo podía comenzar entre ambos pasos.
            $freeze->activate(
                'imported_data_reset',
                $freezeOwner,
                ['request_id' => $requestId]
            );
            $freezeActivated = true;
            $this->assertNoActiveWork();
            $this->assertInventoryHash((string) ($plan['inventory_sha256'] ?? ''));
            // Repetir bajo freeze cierra la carrera entre la comprobación
            // preliminar y la autorización definitiva.
            $this->assertAuthorizedScope($request, $userId);
            $this->assertCandidatePlanUnchanged($request);
            if (!$safety->apiStopped() || !$safety->automationStopped()) {
                throw new RuntimeException('No fue posible confirmar ambas paradas de seguridad.');
            }
            $stmt = $pdo->prepare(
                'UPDATE imported_data_reset_requests
                 SET status="authorized",phase="queued",backup_id=:backup_id,
                     authorized_at=UTC_TIMESTAMP(3),safe_message=:message
                 WHERE id=:id AND requested_by=:user_id AND status="analyzed"'
            );
            $stmt->execute([
                'backup_id' => (int) $backup['id'],
                'message' => 'Solicitud autorizada. Ambas paradas permanecen activas; ejecute el job CLI dedicado.',
                'id' => $requestId,
                'user_id' => $userId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('La solicitud cambió antes de poder autorizarla.');
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($freezeActivated) {
                try {
                    $freeze->release('imported_data_reset', $freezeOwner);
                } catch (Throwable) {
                    // Fallar cerrado: si no puede liberarse, la base continúa
                    // protegida y el administrador verá el freeze existente.
                }
            }
            throw $error;
        }
        return $this->request($requestId, $userId);
    }

    /** @return array<string,mixed> */
    public function pause(int $requestId, int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'UPDATE imported_data_reset_requests
             SET status=IF(status="running","pausing","paused"),
                 safe_message="La operación se pausará después del lote actual."
             WHERE id=:id AND requested_by=:user_id
               AND status IN ("authorized","running","pausing")'
        );
        $stmt->execute(['id' => $requestId, 'user_id' => $userId]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('La solicitud ya no se puede pausar en su estado actual.');
        }
        return $this->request($requestId, $userId);
    }

    /** @return array<string,mixed> */
    public function resume(int $requestId, int $userId): array
    {
        $pdo = Database::connection();
        $freeze = new DatabaseMutationFreezeService();
        $freezeOwner = 'request-' . $requestId;
        $freezeBefore = $freeze->status();
        $activatedHere = empty($freezeBefore['active']);
        $pdo->beginTransaction();
        try {
            $request = $this->lockedRequest($requestId, $userId);
            if ((string) $request['status'] !== 'paused') {
                throw new RuntimeException('Esta solicitud no está pausada.');
            }
            $plan = json_decode((string) $request['plan_json'], true);
            $this->assertInventoryHash((string) ($plan['inventory_sha256'] ?? ''));
            if ($this->freshVerifiedBackup($request) === null) {
                throw new RuntimeException('La copia verificada ya no está disponible.');
            }
            $this->assertAuthorizedScope($request, $userId);
            $this->assertSafety();
            $freeze->activate(
                'imported_data_reset',
                $freezeOwner,
                ['request_id' => $requestId]
            );
            $this->assertNoActiveWork();
            $stmt = $pdo->prepare(
                'UPDATE imported_data_reset_requests
                 SET status="authorized",paused_at=NULL,lease_owner=NULL,
                     lease_expires_at=NULL,safe_message=:message
                 WHERE id=:id AND requested_by=:user_id AND status="paused"'
            );
            $stmt->execute([
                'message' => 'Operación lista para continuar desde el último lote aprobado.',
                'id' => $requestId,
                'user_id' => $userId,
            ]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('La solicitud cambió antes de reanudarla.');
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($activatedHere) {
                try {
                    $freeze->release('imported_data_reset', $freezeOwner);
                } catch (Throwable) {
                }
            }
            throw $error;
        }
        return $this->request($requestId, $userId);
    }

    /** @return array<string,mixed> */
    public function abandon(int $requestId, int $userId): array
    {
        $request = $this->request($requestId, $userId);
        if (!in_array((string) $request['status'], ['paused', 'failed'], true)) {
            throw new RuntimeException('Solo puede cerrar una operación pausada o fallida.');
        }
        $stmt = Database::connection()->prepare(
            'UPDATE imported_data_reset_requests
             SET status="failed",phase="closed",lease_owner=NULL,lease_expires_at=NULL,
                 safe_message=:message
             WHERE id=:id AND requested_by=:user_id AND status IN ("paused","failed")'
        );
        $stmt->execute([
            'message' => 'Solicitud cerrada por el administrador. Los datos ya retirados no se revirtieron; ambas paradas continúan activas.',
            'id' => $requestId,
            'user_id' => $userId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('La solicitud cambió antes de poder cerrarla.');
        }
        (new DatabaseMutationFreezeService())->release(
            'imported_data_reset',
            'request-' . $requestId
        );
        return $this->request($requestId, $userId);
    }

    /** @return array<string,mixed>|null */
    public function runNext(int $requestId = 0): ?array
    {
        $this->assertSafety();
        $owner = 'reset-' . getmypid() . '-' . bin2hex(random_bytes(6));
        $request = $this->claim($requestId, $owner);
        if ($request === null) {
            return null;
        }
        try {
            (new DatabaseMutationFreezeService())->heartbeat(
                'imported_data_reset',
                'request-' . (int) $request['id']
            );
            if ($this->freshVerifiedBackup($request, false) === null) {
                throw new RuntimeException(
                    'La copia vinculada ya no está disponible. No se procesó ningún lote nuevo.'
                );
            }
            $plan = json_decode((string) ($request['plan_json'] ?? '{}'), true);
            $this->assertInventoryHash((string) ($plan['inventory_sha256'] ?? ''));
            return $this->processClaimed($request, $owner);
        } catch (Throwable $error) {
            $this->failClaimed(
                (int) $request['id'],
                $owner,
                (int) $request['lease_generation'],
                $error
            );
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function request(int $requestId, int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM imported_data_reset_requests
             WHERE id=:id AND requested_by=:user_id LIMIT 1'
        );
        $stmt->execute(['id' => $requestId, 'user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new HttpException(404, 'No se encontró la solicitud.');
        }
        return $this->present($row, false);
    }

    /** @return array<string,mixed>|null */
    private function latestRequest(int $userId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM imported_data_reset_requests
             WHERE requested_by=:user_id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $this->present($row, false) : null;
    }

    /** @return list<array<string,mixed>> */
    private function authorizedAccounts(int $userId): array
    {
        $ids = (new BusinessScopeContext())->accountIds($userId);
        if ($ids === []) {
            return [];
        }
        $stmt = Database::connection()->prepare(
            'SELECT a.id,a.account_name,a.company_id,c.name AS company_name
             FROM meli_accounts a
             JOIN companies c ON c.id=a.company_id AND c.status=1
             WHERE a.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
             ORDER BY c.name,a.account_name,a.id'
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Congela la identidad de cada fila candidata. El recuento por sí solo no
     * detecta que una fila haya sido reemplazada por otra entre el análisis y
     * la autorización destructiva.
     *
     * @param array<string,mixed> $operation
     * @param list<int> $accountIds
     * @return array{count:int,sha256:string}
     */
    private function candidateState(array $operation, array $accountIds): array
    {
        $context = hash_init('sha256');
        hash_update($context, (string) ($operation['key'] ?? '') . "\n");
        if (!$this->operationAvailable($operation)) {
            hash_update($context, "not-installed\n");
            return ['count' => 0, 'sha256' => hash_final($context)];
        }
        [$scopeSql, $params] = $this->accountScope($operation, $accountIds);
        $stmt = Database::connection()->prepare(
            'SELECT id FROM `' . $operation['table'] . '`
             WHERE (' . $scopeSql . ')
               AND (' . $operation['extra'] . ')
             ORDER BY id'
        );
        $stmt->execute($params);
        $count = 0;
        while (($id = $stmt->fetchColumn()) !== false) {
            hash_update($context, (string) $id . "\n");
            $count++;
        }
        return ['count' => $count, 'sha256' => hash_final($context)];
    }

    /** @param array<string,mixed> $operation */
    private function operationAvailable(array $operation): bool
    {
        $table = (string) $operation['table'];
        if (!$this->schema->hasTable($table)) {
            return false;
        }
        return match ((string) ($operation['scope'] ?? 'direct')) {
            'question_parent' => $this->schema->hasColumn($table, 'meli_question_id')
                && $this->schema->hasTable('meli_questions')
                && $this->schema->hasColumn('meli_questions', 'meli_account_id'),
            'catalog_job' => $this->schema->hasColumn($table, 'current_account_id')
                && $this->schema->hasTable('catalog_description_job_items')
                && $this->schema->hasColumn('catalog_description_job_items', 'meli_account_id')
                && $this->schema->hasColumn(
                    'catalog_description_job_items',
                    'catalog_description_job_id'
                ),
            'financial_job_parent' =>
                $this->schema->hasColumn($table, 'order_financial_recalc_job_id')
                && $this->schema->hasTable('order_financial_recalc_jobs')
                && $this->schema->hasColumn(
                    'order_financial_recalc_jobs',
                    'meli_account_id'
                )
                && $this->schema->hasTable('order_financial_recalc_job_items')
                && $this->schema->hasColumn(
                    'order_financial_recalc_job_items',
                    'order_financial_recalc_job_id'
                )
                && $this->schema->hasColumn(
                    'order_financial_recalc_job_items',
                    'meli_order_id'
                )
                && $this->schema->hasTable('meli_orders')
                && $this->schema->hasColumn('meli_orders', 'meli_account_id'),
            'financial_job' =>
                $this->schema->hasColumn($table, 'meli_account_id')
                && $this->schema->hasTable('order_financial_recalc_job_items')
                && $this->schema->hasColumn(
                    'order_financial_recalc_job_items',
                    'order_financial_recalc_job_id'
                )
                && $this->schema->hasColumn(
                    'order_financial_recalc_job_items',
                    'meli_order_id'
                )
                && $this->schema->hasTable('meli_orders')
                && $this->schema->hasColumn('meli_orders', 'meli_account_id'),
            'work_projection' =>
                $this->schema->hasColumn($table, 'meli_account_id')
                && $this->schema->hasColumn($table, 'source_table')
                && $this->schema->hasColumn($table, 'source_id')
                && $this->schema->hasTable('order_financial_recalc_job_items')
                && $this->schema->hasColumn(
                    'order_financial_recalc_job_items',
                    'order_financial_recalc_job_id'
                )
                && $this->schema->hasColumn(
                    'order_financial_recalc_job_items',
                    'meli_order_id'
                )
                && $this->schema->hasTable('meli_orders')
                && $this->schema->hasColumn('meli_orders', 'meli_account_id'),
            'manual_campaign' =>
                $this->schema->hasTable('manual_campaign_items')
                && $this->schema->hasColumn(
                    'manual_campaign_items',
                    'manual_campaign_id'
                )
                && $this->schema->hasColumn(
                    'manual_campaign_items',
                    'meli_account_id'
                ),
            'manual_campaign_parent' =>
                $this->schema->hasColumn($table, 'manual_campaign_id')
                && $this->schema->hasTable('manual_campaigns')
                && $this->schema->hasTable('manual_campaign_items')
                && $this->schema->hasColumn(
                    'manual_campaign_items',
                    'manual_campaign_id'
                )
                && $this->schema->hasColumn(
                    'manual_campaign_items',
                    'meli_account_id'
                ),
            'manual_session' =>
                $this->schema->hasTable('manual_processing_items')
                && $this->schema->hasColumn(
                    'manual_processing_items',
                    'manual_processing_session_id'
                )
                && $this->schema->hasColumn(
                    'manual_processing_items',
                    'meli_account_id'
                ),
            'manual_session_parent' =>
                $this->schema->hasColumn($table, 'manual_processing_session_id')
                && $this->schema->hasTable('manual_processing_sessions')
                && $this->schema->hasTable('manual_processing_items')
                && $this->schema->hasColumn(
                    'manual_processing_items',
                    'manual_processing_session_id'
                )
                && $this->schema->hasColumn(
                    'manual_processing_items',
                    'meli_account_id'
                ),
            default => $this->schema->hasColumn(
                $table,
                (string) $operation['account_column']
            ),
        };
    }

    /**
     * @param array<string,mixed> $operation
     * @param list<int> $accountIds
     * @return array{0:string,1:list<int>}
     */
    private function accountScope(array $operation, array $accountIds): array
    {
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if ($accountIds === []) {
            throw new RuntimeException('El alcance autorizado quedó vacío.');
        }
        $marks = implode(',', array_fill(0, count($accountIds), '?'));
        $table = (string) $operation['table'];
        return match ((string) ($operation['scope'] ?? 'direct')) {
            'question_parent' => [
                'EXISTS (SELECT 1 FROM meli_questions reset_question '
                . 'WHERE reset_question.id=`' . $table . '`.meli_question_id '
                . 'AND reset_question.meli_account_id IN (' . $marks . '))',
                $accountIds,
            ],
            'catalog_job' => [
                '('
                . '`' . $table . '`.current_account_id IN (' . $marks . ')'
                . ' OR (`' . $table . '`.current_account_id IS NULL AND EXISTS ('
                . 'SELECT 1 FROM catalog_description_job_items reset_catalog_item '
                . 'WHERE reset_catalog_item.catalog_description_job_id=`' . $table . '`.id '
                . 'AND reset_catalog_item.meli_account_id IN (' . $marks . '))))'
                . ' AND NOT EXISTS ('
                . 'SELECT 1 FROM catalog_description_job_items reset_other_item '
                . 'WHERE reset_other_item.catalog_description_job_id=`' . $table . '`.id '
                . 'AND reset_other_item.meli_account_id NOT IN (' . $marks . '))',
                array_merge($accountIds, $accountIds, $accountIds),
            ],
            'financial_job_parent' => [
                'EXISTS (SELECT 1 FROM order_financial_recalc_jobs reset_financial_job '
                . 'WHERE reset_financial_job.id=`' . $table
                . '`.order_financial_recalc_job_id '
                . 'AND reset_financial_job.meli_account_id IN (' . $marks . '))',
                $accountIds,
            ],
            'financial_job' => [
                '('
                . '`' . $table . '`.meli_account_id IN (' . $marks . ')'
                . ' OR (`' . $table . '`.meli_account_id IS NULL '
                . 'AND EXISTS ('
                . 'SELECT 1 FROM order_financial_recalc_job_items reset_job_item '
                . 'JOIN meli_orders reset_job_order '
                . 'ON reset_job_order.id=reset_job_item.meli_order_id '
                . 'WHERE reset_job_item.order_financial_recalc_job_id=`'
                . $table . '`.id '
                . 'AND reset_job_order.meli_account_id IN (' . $marks . ')) '
                . 'AND NOT EXISTS ('
                . 'SELECT 1 FROM order_financial_recalc_job_items reset_other_job_item '
                . 'JOIN meli_orders reset_other_job_order '
                . 'ON reset_other_job_order.id=reset_other_job_item.meli_order_id '
                . 'WHERE reset_other_job_item.order_financial_recalc_job_id=`'
                . $table . '`.id '
                . 'AND reset_other_job_order.meli_account_id NOT IN (' . $marks . '))))',
                array_merge($accountIds, $accountIds, $accountIds),
            ],
            'work_projection' => [
                '('
                . '`' . $table . '`.meli_account_id IN (' . $marks . ')'
                . ' OR (`' . $table . '`.source_table="order_financial_recalc_jobs" '
                . 'AND EXISTS ('
                . 'SELECT 1 FROM order_financial_recalc_job_items reset_projected_item '
                . 'JOIN meli_orders reset_projected_order '
                . 'ON reset_projected_order.id=reset_projected_item.meli_order_id '
                . 'WHERE reset_projected_item.order_financial_recalc_job_id='
                . 'CAST(`' . $table . '`.source_id AS UNSIGNED) '
                . 'AND reset_projected_order.meli_account_id IN (' . $marks . ')) '
                . 'AND NOT EXISTS ('
                . 'SELECT 1 FROM order_financial_recalc_job_items reset_other_projected_item '
                . 'JOIN meli_orders reset_other_projected_order '
                . 'ON reset_other_projected_order.id=reset_other_projected_item.meli_order_id '
                . 'WHERE reset_other_projected_item.order_financial_recalc_job_id='
                . 'CAST(`' . $table . '`.source_id AS UNSIGNED) '
                . 'AND reset_other_projected_order.meli_account_id NOT IN (' . $marks . '))))',
                array_merge($accountIds, $accountIds, $accountIds),
            ],
            'manual_campaign' => [
                'EXISTS ('
                . 'SELECT 1 FROM manual_campaign_items reset_campaign_item '
                . 'WHERE reset_campaign_item.manual_campaign_id=`' . $table . '`.id '
                . 'AND reset_campaign_item.meli_account_id IN (' . $marks . ')) '
                . 'AND NOT EXISTS ('
                . 'SELECT 1 FROM manual_campaign_items reset_other_campaign_item '
                . 'WHERE reset_other_campaign_item.manual_campaign_id=`' . $table . '`.id '
                . 'AND (reset_other_campaign_item.meli_account_id IS NULL '
                . 'OR reset_other_campaign_item.meli_account_id NOT IN (' . $marks . ')))',
                array_merge($accountIds, $accountIds),
            ],
            'manual_campaign_parent' => [
                'EXISTS ('
                . 'SELECT 1 FROM manual_campaigns reset_campaign '
                . 'WHERE reset_campaign.id=`' . $table . '`.manual_campaign_id '
                . 'AND EXISTS ('
                . 'SELECT 1 FROM manual_campaign_items reset_campaign_item '
                . 'WHERE reset_campaign_item.manual_campaign_id=reset_campaign.id '
                . 'AND reset_campaign_item.meli_account_id IN (' . $marks . ')) '
                . 'AND NOT EXISTS ('
                . 'SELECT 1 FROM manual_campaign_items reset_other_campaign_item '
                . 'WHERE reset_other_campaign_item.manual_campaign_id=reset_campaign.id '
                . 'AND (reset_other_campaign_item.meli_account_id IS NULL '
                . 'OR reset_other_campaign_item.meli_account_id NOT IN (' . $marks . '))))',
                array_merge($accountIds, $accountIds),
            ],
            'manual_session' => [
                'EXISTS ('
                . 'SELECT 1 FROM manual_processing_items reset_session_item '
                . 'WHERE reset_session_item.manual_processing_session_id=`'
                . $table . '`.id '
                . 'AND reset_session_item.meli_account_id IN (' . $marks . ')) '
                . 'AND NOT EXISTS ('
                . 'SELECT 1 FROM manual_processing_items reset_other_session_item '
                . 'WHERE reset_other_session_item.manual_processing_session_id=`'
                . $table . '`.id '
                . 'AND (reset_other_session_item.meli_account_id IS NULL '
                . 'OR reset_other_session_item.meli_account_id NOT IN (' . $marks . ')))',
                array_merge($accountIds, $accountIds),
            ],
            'manual_session_parent' => [
                'EXISTS ('
                . 'SELECT 1 FROM manual_processing_sessions reset_session '
                . 'WHERE reset_session.id=`' . $table
                . '`.manual_processing_session_id '
                . 'AND EXISTS ('
                . 'SELECT 1 FROM manual_processing_items reset_session_item '
                . 'WHERE reset_session_item.manual_processing_session_id=reset_session.id '
                . 'AND reset_session_item.meli_account_id IN (' . $marks . ')) '
                . 'AND NOT EXISTS ('
                . 'SELECT 1 FROM manual_processing_items reset_other_session_item '
                . 'WHERE reset_other_session_item.manual_processing_session_id=reset_session.id '
                . 'AND (reset_other_session_item.meli_account_id IS NULL '
                . 'OR reset_other_session_item.meli_account_id NOT IN (' . $marks . '))))',
                array_merge($accountIds, $accountIds),
            ],
            default => [
                '`' . $table . '`.`' . $operation['account_column'] . '` IN (' . $marks . ')',
                $accountIds,
            ],
        };
    }

    /** @return array<string,mixed>|null */
    private function freshVerifiedBackup(
        array $request,
        bool $cryptographic = true,
        int $expectedBackupId = 0
    ): ?array
    {
        $linkedBackupId = max(0, (int) ($request['backup_id'] ?? 0));
        $selectedBackupId = $linkedBackupId > 0
            ? $linkedBackupId
            : max(0, $expectedBackupId);
        $where = $selectedBackupId > 0
            ? 'id=:backup_id'
                . ($linkedBackupId > 0
                    ? ''
                    : ' AND requested_at>=:analyzed_at AND verified_at>=:verified_after')
            : 'requested_at>=:analyzed_at AND verified_at>=:verified_after';
        $stmt = Database::connection()->prepare(
            'SELECT id,public_id,storage_name,size_bytes,checksum_sha256,
                    manifest_sha256,requested_at,verified_at,status
             FROM system_backup_archives
             WHERE status="ready" AND verified_at IS NOT NULL AND deleted_at IS NULL
               AND ' . $where . '
             ORDER BY verified_at DESC,id DESC LIMIT 1'
        );
        $params = [
            'analyzed_at' => (string) $request['created_at'],
            'verified_after' => (string) $request['created_at'],
        ];
        if ($selectedBackupId > 0) {
            $params['backup_id'] = $selectedBackupId;
        }
        if ($linkedBackupId > 0) {
            unset($params['analyzed_at'], $params['verified_after']);
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $path = AppPaths::backups() . '/' . basename((string) ($row['storage_name'] ?? ''));
        if (!is_file($path) || (int) @filesize($path) !== (int) ($row['size_bytes'] ?? 0)) {
            return null;
        }
        if (!$cryptographic) {
            return $row;
        }
        $checksum = (string) hash_file('sha256', $path);
        if (
            strlen($checksum) !== 64
            || !hash_equals((string) ($row['checksum_sha256'] ?? ''), $checksum)
        ) {
            return null;
        }
        try {
            $verified = (new BackupArchiveService())->verify($path);
        } catch (Throwable) {
            return null;
        }
        return hash_equals(
            (string) ($row['manifest_sha256'] ?? ''),
            $verified['manifest_checksum']
        ) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    private function claim(int $requestId, string $owner): ?array
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $where = $requestId > 0 ? 'id=:id AND ' : '';
            $stmt = $pdo->prepare(
                'SELECT * FROM imported_data_reset_requests
                 WHERE ' . $where . '
                   status IN ("authorized","running","pausing")
                   AND (lease_expires_at IS NULL OR lease_expires_at<UTC_TIMESTAMP(3))
                 ORDER BY id LIMIT 1 FOR UPDATE'
            );
            $stmt->execute($requestId > 0 ? ['id' => $requestId] : []);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                $pdo->rollBack();
                return null;
            }
            if ((string) $row['status'] === 'pausing') {
                $pdo->prepare(
                    'UPDATE imported_data_reset_requests
                     SET status="paused",paused_at=UTC_TIMESTAMP(3),
                         lease_owner=NULL,lease_expires_at=NULL,
                         safe_message="Operación pausada. Ambas paradas continúan activas."
                     WHERE id=?'
                )->execute([(int) $row['id']]);
                $pdo->commit();
                return null;
            }
            $leaseGeneration = (int) $row['lease_generation'] + 1;
            $pdo->prepare(
                'UPDATE imported_data_reset_requests
                 SET status="running",phase=IF(phase="queued","delete",phase),
                     lease_owner=:owner,lease_generation=:generation,
                     lease_expires_at=DATE_ADD(UTC_TIMESTAMP(3),INTERVAL 90 SECOND),
                     heartbeat_at=UTC_TIMESTAMP(3),
                     started_at=COALESCE(started_at,UTC_TIMESTAMP(3)),
                     safe_message="Procesando un lote local verificado."
                 WHERE id=:id'
            )->execute([
                'owner' => $owner,
                'generation' => $leaseGeneration,
                'id' => (int) $row['id'],
            ]);
            $pdo->commit();
            $row['lease_owner'] = $owner;
            $row['lease_generation'] = $leaseGeneration;
            $row['status'] = 'running';
            $row['phase'] = (string) $row['phase'] === 'queued' ? 'delete' : $row['phase'];
            return $row;
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function processClaimed(array $request, string $owner): array
    {
        $request = $this->present($request, true);
        $operations = $request['plan']['operations'] ?? [];
        $position = max(0, (int) $request['phase_position']);
        if ((string) $request['phase'] === 'verify' || $position >= count($operations)) {
            return $this->verifyAndComplete($request, $owner);
        }
        $operation = $operations[$position];
        if (!is_array($operation) || !$this->operationAvailable($operation)) {
            return $this->advanceOperation($request, $owner, 'El conjunto no existe en esta instalación.');
        }
        $ids = $this->candidateIds(
            $operation,
            $request['scope']['account_ids'] ?? [],
            (int) $request['cursor_id']
        );
        if ($ids === []) {
            return $this->advanceOperation($request, $owner, 'Conjunto revisado.');
        }
        return $this->deleteBatch($request, $operation, $ids, $owner);
    }

    /**
     * @param array<string,mixed> $operation
     * @param list<int> $accountIds
     * @return list<int>
     */
    private function candidateIds(array $operation, array $accountIds, int $cursor): array
    {
        $accountIds = array_values(array_filter(array_map('intval', $accountIds)));
        if ($accountIds === []) {
            throw new RuntimeException('El alcance autorizado quedó vacío.');
        }
        [$scopeSql, $scopeParams] = $this->accountScope($operation, $accountIds);
        $batch = 250;
        $stmt = Database::connection()->prepare(
            'SELECT id FROM `' . $operation['table'] . '`
             WHERE id>?
               AND (' . $scopeSql . ')
               AND (' . $operation['extra'] . ')
             ORDER BY id LIMIT ' . $batch
        );
        $stmt->bindValue(1, $cursor, PDO::PARAM_INT);
        foreach ($scopeParams as $index => $accountId) {
            $stmt->bindValue($index + 2, $accountId, PDO::PARAM_INT);
        }
        $stmt->execute();
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @param array<string,mixed> $operation @param list<int> $ids */
    private function deleteBatch(array $request, array $operation, array $ids, string $owner): array
    {
        $pdo = Database::connection();
        $payloadFiles = [];
        $pdo->beginTransaction();
        try {
            $this->assertLease($request, $owner);
            $payloadEntities = $this->prepareRemotePayloadEntities(
                $pdo,
                (string) $operation['table'],
                $ids
            );
            $membershipEntities = $this->prepareColdMembershipEntities(
                $pdo,
                (string) $operation['table'],
                $ids
            );
            $deleted = 0;
            $deletedIds = [];
            $retained = 0;
            $delete = $pdo->prepare('DELETE FROM `' . $operation['table'] . '` WHERE id=?');
            foreach ($ids as $id) {
                try {
                    $delete->execute([$id]);
                    $deleted += $delete->rowCount();
                    if ($delete->rowCount() === 1) {
                        $deletedIds[] = $id;
                    } else {
                        $retained++;
                    }
                } catch (PDOException $error) {
                    if ((string) $error->getCode() !== '23000') {
                        throw $error;
                    }
                    $retained++;
                }
            }
            $payloadFiles = $this->detachRemotePayloads(
                $pdo,
                $payloadEntities,
                $deletedIds
            );
            $this->markPreparedColdMembershipsDeleted(
                $pdo,
                $membershipEntities,
                $deletedIds
            );
            $counters = $request['counters'];
            $counters['reviewed'] += count($ids);
            $counters['deleted'] += $deleted;
            $counters['retained'] += $retained;
            $sequence = $this->nextSequence((int) $request['id']);
            $pdo->prepare(
                'INSERT INTO imported_data_reset_steps
                 (reset_request_id,sequence_no,generation,phase,dataset_key,status,
                  rows_reviewed,rows_deleted,rows_retained,cursor_before,cursor_after,
                  safe_message,completed_at)
                 VALUES (?,?,?,?,?,"completed",?,?,?,?,?,?,UTC_TIMESTAMP(3))'
            )->execute([
                (int) $request['id'],
                $sequence,
                (int) $request['lease_generation'],
                'delete',
                (string) $operation['key'],
                count($ids),
                $deleted,
                $retained,
                (int) $request['cursor_id'],
                max($ids),
                $deleted . ' filas retiradas; ' . $retained . ' conservadas por seguridad.',
            ]);
            $update = $pdo->prepare(
                'UPDATE imported_data_reset_requests
                 SET cursor_id=:cursor,counters_json=:counters,
                     heartbeat_at=UTC_TIMESTAMP(3),lease_owner=NULL,lease_expires_at=NULL,
                     safe_message=:message
                 WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation'
            );
            $update->execute([
                'cursor' => max($ids),
                'counters' => $this->json($counters),
                'message' => $operation['label'] . ': lote aprobado.',
                'id' => (int) $request['id'],
                'owner' => $owner,
                'generation' => (int) $request['lease_generation'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'La reserva venció antes de aprobar el lote; no se guardaron sus cambios.'
                );
            }
            $pdo->commit();
            try {
                (new FileRemotePayloadStore())->purgeOrphans(
                    min(500, max(100, count($payloadFiles)))
                );
            } catch (Throwable $error) {
                // El recurso comercial ya quedó retirado, pero el objeto y su
                // referencia de archivo permanecen en MariaDB para que una
                // limpieza posterior pueda reintentarlo sin perder trazabilidad.
                error_log(
                    'IMPORTED_DATA_RESET_PAYLOAD_PURGE_DEFERRED class='
                    . $error::class
                );
            }
            return ['status' => 'running', 'request_id' => (int) $request['id'], 'message' => 'Lote aprobado.'];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    /**
     * Quita referencias genéricas sin FK antes de borrar sus entidades. Un
     * objeto compartido solo se retira cuando ya no conserva referencia.
     *
     * @param list<int> $ids
     * @return array<string,array<int,list<int>>>
     */
    private function prepareRemotePayloadEntities(PDO $pdo, string $table, array $ids): array
    {
        if (
            $ids === []
            || !$this->schema->hasTable('remote_payload_references')
            || !$this->schema->hasTable('remote_payload_objects')
        ) {
            return [];
        }
        $entities = [];
        if (in_array($table, [
            'meli_orders','meli_shipments','meli_payments','meli_packs','meli_order_items',
        ], true)) {
            foreach ($ids as $id) {
                $entities[$table][(int) $id] = [(int) $id];
            }
        }
        if ($table === 'meli_orders' && $this->schema->hasTable('meli_order_items')) {
            $stmt = $pdo->prepare(
                'SELECT id,meli_order_id FROM meli_order_items WHERE meli_order_id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')'
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $entities['meli_order_items'][(int) $row['meli_order_id']][] =
                    (int) $row['id'];
            }
        }
        return $entities;
    }

    /**
     * @param array<string,array<int,list<int>>> $prepared
     * @param list<int> $deletedParentIds
     * @return list<string> archivos ya desvinculados para retirar tras commit
     */
    private function detachRemotePayloads(
        PDO $pdo,
        array $prepared,
        array $deletedParentIds
    ): array
    {
        if ($prepared === [] || $deletedParentIds === []) {
            return [];
        }
        $approvedParents = array_fill_keys(array_map('intval', $deletedParentIds), true);
        $entities = [];
        foreach ($prepared as $entityTable => $byParent) {
            foreach ($byParent as $parentId => $entityIds) {
                if (isset($approvedParents[(int) $parentId])) {
                    $entities[$entityTable] = array_merge(
                        $entities[$entityTable] ?? [],
                        $entityIds
                    );
                }
            }
        }
        $objectIds = [];
        foreach ($entities as $entityTable => $entityIds) {
            $entityIds = array_values(array_unique(array_filter(array_map('intval', $entityIds))));
            if ($entityIds === []) {
                continue;
            }
            $marks = implode(',', array_fill(0, count($entityIds), '?'));
            $stmt = $pdo->prepare(
                'SELECT payload_object_id FROM remote_payload_references
                 WHERE entity_table=? AND entity_id IN (' . $marks . ') FOR UPDATE'
            );
            $stmt->execute(array_merge([$entityTable], $entityIds));
            $objectIds = array_merge($objectIds, array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
            $pdo->prepare(
                'DELETE FROM remote_payload_references
                 WHERE entity_table=? AND entity_id IN (' . $marks . ')'
            )->execute(array_merge([$entityTable], $entityIds));
        }
        $objectIds = array_values(array_unique(array_filter($objectIds)));
        if ($objectIds === []) {
            return [];
        }
        $marks = implode(',', array_fill(0, count($objectIds), '?'));
        $pdo->prepare(
            'UPDATE remote_payload_objects o
             SET reference_count=(
                 SELECT COUNT(*) FROM remote_payload_references r
                 WHERE r.payload_object_id=o.id
             )
             WHERE o.id IN (' . $marks . ')'
        )->execute($objectIds);
        // El archivo y su fila se retiran después del commit mediante
        // FileRemotePayloadStore::purgeOrphans(). Si el disco falla, la fila
        // queda disponible para un reintento y no nace un archivo invisible.
        return array_map('strval', $objectIds);
    }

    /**
     * Congela las identidades que una eliminación del padre retirará por
     * cascada. Así el archivo frío conserva un tombstone verificable también
     * para las hijas que ya no existirán después del DELETE.
     *
     * @param list<int> $ids
     * @return array<string,array<int,list<int>>>
     */
    private function prepareColdMembershipEntities(
        PDO $pdo,
        string $sourceTable,
        array $ids
    ): array {
        if (
            $ids === []
            || !$this->schema->hasTable('system_cold_archive_memberships')
        ) {
            return [];
        }
        $entities = [];
        foreach ($ids as $id) {
            $entities[$sourceTable][(int) $id] = [(int) $id];
        }
        if (
            $sourceTable === 'order_financial_recalc_jobs'
            && $this->schema->hasTable('order_financial_recalc_job_items')
        ) {
            $stmt = $pdo->prepare(
                'SELECT id,order_financial_recalc_job_id
                 FROM order_financial_recalc_job_items
                 WHERE order_financial_recalc_job_id IN ('
                . implode(',', array_fill(0, count($ids), '?')) . ')'
            );
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $entities['order_financial_recalc_job_items'][
                    (int) $row['order_financial_recalc_job_id']
                ][] = (int) $row['id'];
            }
        }
        return $entities;
    }

    /**
     * @param array<string,array<int,list<int>>> $prepared
     * @param list<int> $deletedParentIds
     */
    private function markPreparedColdMembershipsDeleted(
        PDO $pdo,
        array $prepared,
        array $deletedParentIds
    ): void {
        if ($prepared === [] || $deletedParentIds === []) {
            return;
        }
        $approvedParents = array_fill_keys(array_map('intval', $deletedParentIds), true);
        foreach ($prepared as $sourceTable => $byParent) {
            $sourceIds = [];
            foreach ($byParent as $parentId => $ids) {
                if (isset($approvedParents[(int) $parentId])) {
                    $sourceIds = array_merge($sourceIds, $ids);
                }
            }
            $sourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceIds))));
            if ($sourceIds === []) {
                continue;
            }
            $stmt = $pdo->prepare(
                'UPDATE system_cold_archive_memberships
                 SET source_deleted_at=UTC_TIMESTAMP(3),verification_error=NULL
                 WHERE source_table=?
                   AND source_deleted_at IS NULL AND stale_at IS NULL
                   AND source_id IN ('
                . implode(',', array_fill(0, count($sourceIds), '?')) . ')'
            );
            $stmt->execute(array_merge([(string) $sourceTable], $sourceIds));
        }
    }

    /** @return array<string,mixed> */
    private function advanceOperation(array $request, string $owner, string $message): array
    {
        $next = (int) $request['phase_position'] + 1;
        $total = count($request['plan']['operations'] ?? []);
        $phase = $next >= $total ? 'verify' : 'delete';
        $stmt = Database::connection()->prepare(
            'UPDATE imported_data_reset_requests
             SET phase=:phase,phase_position=:position,cursor_id=0,
                 lease_owner=NULL,lease_expires_at=NULL,heartbeat_at=UTC_TIMESTAMP(3),
                 safe_message=:message
             WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation'
        );
        $stmt->execute([
            'phase' => $phase,
            'position' => $next,
            'message' => $message,
            'id' => (int) $request['id'],
            'owner' => $owner,
            'generation' => (int) $request['lease_generation'],
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('La reserva venció antes de avanzar.');
        }
        return ['status' => 'running', 'request_id' => (int) $request['id'], 'message' => $message];
    }

    /** @return array<string,mixed> */
    private function verifyAndComplete(array $request, string $owner): array
    {
        $after = $this->integritySnapshot();
        if (!hash_equals((string) $request['integrity_sha256'], $this->hash($after))) {
            throw new RuntimeException(
                'La verificación detectó cambios en configuración o evidencia protegida. Las paradas continúan activas.'
            );
        }
        $accountIds = array_values(array_filter(array_map(
            'intval',
            $request['scope']['account_ids'] ?? []
        )));
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $this->assertLease($request, $owner);
            foreach ($accountIds as $accountId) {
                $pdo->prepare(
                    'INSERT INTO imported_data_reset_generations
                     (meli_account_id,generation,last_reset_request_id,reset_at)
                     VALUES (?,2,?,UTC_TIMESTAMP(3))
                     ON DUPLICATE KEY UPDATE
                       generation=generation+1,last_reset_request_id=VALUES(last_reset_request_id),
                       reset_at=VALUES(reset_at)'
                )->execute([$accountId, (int) $request['id']]);
            }
            if ($accountIds !== []) {
                $pdo->prepare(
                    'UPDATE meli_accounts SET last_sync_at=NULL,last_error=NULL
                     WHERE id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')'
                )->execute($accountIds);
            }
            $update = $pdo->prepare(
                'UPDATE imported_data_reset_requests
                 SET status="completed",phase="completed",integrity_after_json=:integrity,
                     completed_at=UTC_TIMESTAMP(3),lease_owner=NULL,lease_expires_at=NULL,
                     safe_message=:message
                 WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation'
            );
            $update->execute([
                'integrity' => $this->json($after),
                'message' => 'Restablecimiento verificado. Las paradas siguen activas hasta una reactivación separada.',
                'id' => (int) $request['id'],
                'owner' => $owner,
                'generation' => (int) $request['lease_generation'],
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException(
                    'La reserva venció antes de aprobar la verificación final.'
                );
            }
            $pdo->commit();
            try {
                (new DatabaseMutationFreezeService())->release(
                    'imported_data_reset',
                    'request-' . (int) $request['id']
                );
            } catch (Throwable $error) {
                error_log(
                    'IMPORTED_DATA_RESET_FREEZE_RELEASE_FAILURE request='
                    . (int) $request['id']
                    . ' class=' . $error::class
                );
            }
            return ['status' => 'completed', 'request_id' => (int) $request['id'], 'message' => 'Verificación completada.'];
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    private function assertLease(array $request, string $owner): void
    {
        $stmt = Database::connection()->prepare(
            'SELECT id FROM imported_data_reset_requests
             WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation
               AND lease_expires_at>=UTC_TIMESTAMP(3)
             FOR UPDATE'
        );
        $stmt->execute([
            'id' => (int) $request['id'],
            'owner' => $owner,
            'generation' => (int) $request['lease_generation'],
        ]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('La reserva del trabajo venció; no se guardó el lote.');
        }
    }

    private function assertSafety(): void
    {
        $safety = new EmergencyControlService();
        if (!$safety->apiStopped() || !$safety->automationStopped()) {
            throw new RuntimeException(
                'El restablecimiento exige Mercado Libre y la automatización detenidos.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function lockedRequest(int $requestId, int $userId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM imported_data_reset_requests
             WHERE id=:id AND requested_by=:user_id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $requestId, 'user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new HttpException(404, 'No se encontró la solicitud.');
        }
        return $row;
    }

    private function nextSequence(int $requestId): int
    {
        $stmt = Database::connection()->prepare(
            'SELECT COALESCE(MAX(sequence_no),0)+1
             FROM imported_data_reset_steps WHERE reset_request_id=?'
        );
        $stmt->execute([$requestId]);
        return max(1, (int) $stmt->fetchColumn());
    }

    private function failClaimed(
        int $requestId,
        string $owner,
        int $generation,
        Throwable $error
    ): void {
        $diagnostic = 'RESET-' . gmdate('Ymd-His') . '-' . substr(hash('sha256', random_bytes(16)), 0, 8);
        $stmt = Database::connection()->prepare(
            'UPDATE imported_data_reset_requests
             SET status="failed",lease_owner=NULL,lease_expires_at=NULL,
                 safe_message=:message
             WHERE id=:id AND lease_owner=:owner AND lease_generation=:generation
               AND status IN ("running","pausing")'
        );
        $stmt->execute([
            'message' => 'La operación se detuvo de forma segura. Diagnóstico: ' . $diagnostic . '.',
            'id' => $requestId,
            'owner' => $owner,
            'generation' => $generation,
        ]);
        if ($stmt->rowCount() === 1) {
            error_log('IMPORTED_DATA_RESET_FAILURE diagnostic=' . $diagnostic . ' class=' . $error::class);
        }
    }

    /** @return array{sha256:string,count:int} */
    private function assertClassifiedInventory(): array
    {
        if (!$this->schema->hasTable('imported_data_reset_table_policy')) {
            throw new RuntimeException('Complete la migración del restablecimiento antes de analizar.');
        }
        $actual = $this->schema->baseTables();
        $rows = Database::connection()->query(
            'SELECT table_name,action,note
             FROM imported_data_reset_table_policy ORDER BY table_name'
        )->fetchAll(PDO::FETCH_ASSOC);
        $classified = [];
        foreach ($rows as $row) {
            $name = (string) ($row['table_name'] ?? '');
            if ($name !== '') {
                $classified[$name] = [
                    'action' => (string) ($row['action'] ?? ''),
                    'note' => trim((string) ($row['note'] ?? '')),
                ];
            }
        }
        $unknown = [];
        foreach ($actual as $table) {
            if (!isset($classified[$table]) || $classified[$table]['note'] === '') {
                $unknown[] = $table;
            }
        }
        if ($unknown !== []) {
            throw new RuntimeException(
                'La base contiene ' . count($unknown)
                . ' conjuntos sin una política revisada. No se autorizó el análisis.'
            );
        }
        $explicitBusiness = array_flip($this->policy->explicitBusinessTables());
        foreach ($actual as $table) {
            if (
                preg_match('/^(meli_|ml_|sync_|sales_|sale_|order_|manual_)/', $table) === 1
                && !isset($explicitBusiness[$table])
            ) {
                throw new RuntimeException(
                    'La tabla de negocio ' . $table
                    . ' no pertenece al inventario cerrado de esta versión.'
                );
            }
            if (
                isset($explicitBusiness[$table])
                && str_contains(
                    mb_strtolower((string) ($classified[$table]['note'] ?? '')),
                    'predeterminada'
                )
            ) {
                throw new RuntimeException(
                    'La tabla de negocio ' . $table . ' todavía no tiene una razón explícita.'
                );
            }
        }
        foreach ($this->policy->operations() as $operation) {
            $table = (string) $operation['table'];
            if (
                in_array($table, $actual, true)
                && !in_array((string) ($classified[$table]['action'] ?? ''), ['delete', 'reset'], true)
            ) {
                throw new RuntimeException(
                    'La política persistente no autoriza el conjunto ' . $operation['label'] . '.'
                );
            }
        }
        $inventory = [];
        foreach ($actual as $table) {
            $inventory[$table] = (string) $classified[$table]['action'];
        }
        return [
            'sha256' => hash('sha256', $this->json($inventory)),
            'count' => count($inventory),
        ];
    }

    private function assertInventoryHash(string $expected): void
    {
        $current = $this->assertClassifiedInventory();
        if ($expected === '' || !hash_equals($expected, $current['sha256'])) {
            throw new RuntimeException(
                'El inventario de tablas cambió después del análisis. Analice nuevamente antes de borrar.'
            );
        }
    }

    /** @param array<string,mixed> $request */
    private function assertAuthorizedScope(array $request, int $userId): void
    {
        $scope = json_decode((string) ($request['scope_json'] ?? '{}'), true);
        $expected = array_values(array_unique(array_filter(array_map(
            'intval',
            is_array($scope['account_ids'] ?? null) ? $scope['account_ids'] : []
        ))));
        $current = array_map(
            static fn (array $account): int => (int) $account['id'],
            $this->authorizedAccounts($userId)
        );
        sort($expected, SORT_NUMERIC);
        sort($current, SORT_NUMERIC);
        if ($expected === [] || $expected !== $current) {
            throw new RuntimeException(
                'El acceso a empresas o cuentas cambió después del análisis. '
                . 'Genere un análisis nuevo antes de autorizar.'
            );
        }
    }

    /** @param array<string,mixed> $request */
    private function assertCandidatePlanUnchanged(array $request): void
    {
        $scope = json_decode((string) ($request['scope_json'] ?? '{}'), true);
        $plan = json_decode((string) ($request['plan_json'] ?? '{}'), true);
        $accountIds = array_values(array_filter(array_map(
            'intval',
            is_array($scope['account_ids'] ?? null) ? $scope['account_ids'] : []
        )));
        $operations = is_array($plan['operations'] ?? null) ? $plan['operations'] : [];
        if ((int) ($plan['policy_version'] ?? 0) < 2 || $operations === []) {
            throw new RuntimeException(
                'Este análisis pertenece a una política anterior. Genere uno nuevo antes de autorizar.'
            );
        }
        $this->primeOperationSchema($operations);
        foreach ($operations as $operation) {
            if (!is_array($operation)) {
                throw new RuntimeException('El plan analizado no tiene un formato válido.');
            }
            $expectedHash = (string) ($operation['candidate_sha256'] ?? '');
            $expectedCount = max(0, (int) ($operation['candidate_count'] ?? 0));
            $current = $this->candidateState($operation, $accountIds);
            if (
                strlen($expectedHash) !== 64
                || !hash_equals($expectedHash, $current['sha256'])
                || $expectedCount !== $current['count']
            ) {
                throw new RuntimeException(
                    'Los datos importados cambiaron después del análisis. '
                    . 'No se autorizó ningún borrado; genere un análisis nuevo.'
                );
            }
        }
    }

    private function assertNoActiveWork(): void
    {
        $tables = array_values(array_filter(
            $this->schema->baseTables(),
            static fn (string $table): bool => str_ends_with($table, '_jobs')
                || str_ends_with($table, '_sessions')
                || str_ends_with($table, '_work_items')
                || in_array(
                    $table,
                    [
                        'manual_campaigns',
                        'database_maintenance_sessions',
                        // Esta tabla es adquirida directamente por el
                        // orquestador y forma parte del plan destructivo.
                        'sync_batch_chunks',
                    ],
                    true
                )
        ));
        $columnMap = $this->schema->columnNamesForTables($tables);
        foreach ($tables as $table) {
            if (!isset($columnMap[$table]['status'])) {
                continue;
            }
            $columns = $this->schema->columns($table);
            $freshness = '1=1';
            if ($table !== 'manual_campaigns') {
                foreach ([
                    'lease_expires_at' => '>=UTC_TIMESTAMP(3)',
                    'lock_expires_at' => '>=UTC_TIMESTAMP(3)',
                    'control_expires_at' => '>=UTC_TIMESTAMP(3)',
                    'worker_heartbeat_at' => '>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 2 MINUTE)',
                    'heartbeat_at' => '>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 2 MINUTE)',
                ] as $column => $predicate) {
                    if (isset($columns[$column])) {
                        $freshness = '`' . $column . '`' . $predicate;
                        break;
                    }
                }
            }
            if ($freshness === '1=1') {
                // Un estado activo sin lease/heartbeat comprobable no puede
                // considerarse seguro durante una operación destructiva.
                $freshness = '1=1';
            }
            $condition = 'LOWER(CAST(`status` AS CHAR)) IN '
                . '("running","processing","active","pausing") AND (' . $freshness . ')';
            if (!$this->schema->hasTable($table)) {
                continue;
            }
            try {
                $active = (int) Database::connection()->query(
                    'SELECT COUNT(*) FROM `' . $table . '` WHERE ' . $condition
                )->fetchColumn();
            } catch (PDOException) {
                throw new RuntimeException(
                    'No se pudo comprobar que todos los procesos estuvieran detenidos.'
                );
            }
            if ($active > 0) {
                if ($table === 'manual_campaigns') {
                    throw new RuntimeException(
                        'Existe una campaña dirigida activa (por ejemplo, la campaña #6). '
                        . 'Finalícela o devuelva sus pendientes explícitamente antes de borrar datos importados.'
                    );
                }
                throw new RuntimeException(
                    'Todavía hay ' . $active . ' trabajos activos. Espere su cierre y vuelva a autorizar.'
                );
            }
        }
    }

    /** @return array<string,array{rows:int,data_sha256:string,excluded_columns:list<string>}> */
    private function integritySnapshot(): array
    {
        $snapshot = [];
        $operationalExceptions = [
            'system_backup_archives',
            'system_backup_audit_events',
            'imported_data_reset_requests',
            'imported_data_reset_steps',
            'imported_data_reset_generations',
        ];
        $tables = Database::connection()->query(
            'SELECT table_name FROM imported_data_reset_table_policy
             WHERE action="preserve" ORDER BY table_name'
        )->fetchAll(PDO::FETCH_COLUMN);
        $tables = array_map('strval', $tables);
        $this->schema->tablesExist($tables);
        $this->schema->columnNamesForTables($tables);
        foreach ($tables as $table) {
            if (in_array($table, $operationalExceptions, true)) {
                continue;
            }
            if (!$this->schema->hasTable($table)) {
                continue;
            }
            $columns = $this->schema->columns($table);
            $excluded = $this->volatileColumns($table);
            $selected = array_values(array_filter(
                array_keys($columns),
                static fn (string $column): bool => !in_array($column, $excluded, true)
            ));
            $primary = [];
            foreach ($columns as $column => $metadata) {
                if ((string) ($metadata['Key'] ?? '') === 'PRI') {
                    $primary[] = $column;
                }
            }
            $order = $primary !== [] ? $primary : $selected;
            $selectSql = implode(',', array_map(
                static fn (string $column): string => '`' . $column . '`',
                $selected
            ));
            $orderSql = implode(',', array_map(
                static fn (string $column): string => '`' . $column . '`',
                $order
            ));
            $stmt = Database::connection()->query(
                'SELECT ' . $selectSql . ' FROM `' . $table . '`'
                . ($orderSql !== '' ? ' ORDER BY ' . $orderSql : '')
            );
            $context = hash_init('sha256');
            $rows = 0;
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                ksort($row);
                hash_update($context, $this->json($row) . "\n");
                $rows++;
            }
            $snapshot[$table] = [
                'rows' => $rows,
                'data_sha256' => hash_final($context),
                'excluded_columns' => $excluded,
            ];
        }
        ksort($snapshot);
        return $snapshot;
    }

    /** @param list<array<string,mixed>> $operations */
    private function primeOperationSchema(array $operations): void
    {
        $tables = array_values(array_unique(array_filter(array_map(
            static fn (array $operation): string => (string) ($operation['table'] ?? ''),
            $operations
        ))));
        if ($tables === []) {
            return;
        }
        $this->schema->tablesExist($tables);
        $this->schema->columnNamesForTables($tables);
    }

    /** @return list<string> */
    private function volatileColumns(string $table): array
    {
        return match ($table) {
            'users' => ['last_login_at', 'updated_at'],
            'meli_accounts' => ['last_sync_at', 'last_error', 'updated_at'],
            'meli_tokens' => ['updated_at'],
            'app_settings', 'company_settings' => ['updated_at'],
            // La evidencia exacta (archivo, tabla, id y hash) permanece
            // inmutable. Solo se aprueba el tombstone que indica que otra
            // política local retiró la fuente normalizada.
            'system_cold_archive_memberships' => ['source_deleted_at'],
            // La relación local desaparece de forma deliberada cuando se
            // retira una orden importada, pero la evidencia remota del mes
            // permanece inmutable.
            'sync_sales_audit_remote_ids' => ['found_local_order_id'],
            // El evento de una corrida conserva cola, fuente y resultado. La
            // proyección operativa es reconstruible y su FK SET NULL se
            // aprueba deliberadamente al retirar el backlog importado.
            'system_work_queue_run_items' => ['projection_id'],
            default => [],
        };
    }

    /** @return list<array<string,mixed>> */
    private function recentSteps(int $requestId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT sequence_no,dataset_key,status,rows_reviewed,rows_deleted,
                    rows_retained,safe_message,completed_at
             FROM imported_data_reset_steps
             WHERE reset_request_id=? ORDER BY sequence_no DESC LIMIT 10'
        );
        $stmt->execute([$requestId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<string,mixed> */
    private function present(array $row, bool $includeTechnicalPlan): array
    {
        foreach (['scope_json' => 'scope', 'plan_json' => 'plan', 'counters_json' => 'counters'] as $json => $key) {
            $decoded = json_decode((string) ($row[$json] ?? '{}'), true);
            $row[$key] = is_array($decoded) ? $decoded : [];
            unset($row[$json]);
        }
        if (!$includeTechnicalPlan && is_array($row['plan']['operations'] ?? null)) {
            $row['plan']['operations'] = array_map(
                static fn (array $operation): array => [
                    'key' => (string) ($operation['key'] ?? ''),
                    'label' => (string) ($operation['label'] ?? ''),
                    'candidate_count' => (int) ($operation['candidate_count'] ?? 0),
                ],
                $row['plan']['operations']
            );
        }
        unset($row['integrity_before_json'], $row['integrity_after_json']);
        return $row;
    }

    /** @return array{reviewed:int,deleted:int,retained:int,errors:int} */
    private function emptyCounters(): array
    {
        return ['reviewed' => 0, 'deleted' => 0, 'retained' => 0, 'errors' => 0];
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        ksort($value);
        return hash('sha256', $this->json($value));
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
