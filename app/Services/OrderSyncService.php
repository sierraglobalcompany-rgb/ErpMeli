<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\ValueObjects\PayloadContext;
use DateTimeImmutable;
use PDO;
use Throwable;

final class OrderSyncService
{
    private MeliApiClient $api;
    private MeliDateTimeNormalizer $dateNormalizer;
    private ?bool $payloadCatalogAvailable = null;
    /** @var array<string,array<string,mixed>> */
    private array $resourceCache = [];

    public function __construct(private readonly int $accountId, ?MeliApiClient $api = null)
    {
        $this->api = $api ?? new MeliApiClient($accountId);
        $this->dateNormalizer = new MeliDateTimeNormalizer();
    }

    public function syncRange(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $pdo = Database::connection();
        $lock = new SyncLockService();
        $runs = new SyncRunService();
        $settings = new SyncSettingsService();
        $lockId = $lock->acquire($this->accountId, 'orders', $from, $to);
        $runId = 0;
        $logId = 0;
        $count = 0;
        try {
            $runId = $runs->start($this->accountId, 'orders', $from, $to);
            $sellerId = $this->sellerId();
            $startedAt = date('Y-m-d H:i:s');
            $log = $pdo->prepare("INSERT INTO meli_sync_logs (meli_account_id,sync_type,status,started_at) VALUES (:account,'orders','started',:started)");
            $log->execute(['account' => $this->accountId, 'started' => $startedAt]);
            $logId = (int) $pdo->lastInsertId();
            $offset = 0;
            $limit = $settings->pageLimit();
            $maxOrders = $settings->maxOrdersPerRun();
            $maxApiPages = $settings->maxApiPagesPerRun();
            $pages = 0;
            do {
                $page = $this->api->get('/orders/search', [
                    'seller' => $sellerId,
                    'order.date_created.from' => $from->format(DATE_ATOM),
                    'order.date_created.to' => $to->format(DATE_ATOM),
                    'sort' => 'date_desc',
                    'offset' => $offset,
                    'limit' => $limit,
                ], ['job_type' => 'orders_sync', 'source' => PHP_SAPI === 'cli' ? 'cron' : 'web', 'bulk' => true]);
                $pages++;
                $results = is_array($page['results'] ?? null) ? $page['results'] : [];
                $processedFromPage = 0;
                foreach ($results as $order) {
                    if (!empty($order['id'])) {
                        $this->persistOrder($order);
                        $count++;
                        $processedFromPage++;
                        if ($count >= $maxOrders) {
                            Logger::write('warning', 'Sincronización detenida por máximo de órdenes por ejecución.', ['account_id' => $this->accountId, 'max_orders' => $maxOrders]);
                            break;
                        }
                    }
                }
                $offset += $count >= $maxOrders ? $processedFromPage : count($results);
                $total = (int) ($page['paging']['total'] ?? 0);
                $this->checkpoint($from, $to, (string) $offset, $offset >= $total ? 'complete' : 'running');
                if ($results !== [] && $offset < $total && $count < $maxOrders && $settings->pauseMs() > 0) {
                    usleep($settings->pauseMs() * 1000);
                }
            } while (
                $results !== []
                && $offset < $total
                && $count < $maxOrders
                && $pages < $maxApiPages
            );
            $pdo->prepare("UPDATE meli_accounts SET last_sync_at=NOW(), status='conectado', last_error=NULL WHERE id=:id")->execute(['id' => $this->accountId]);
            $pdo->prepare("UPDATE meli_sync_logs SET status='success',processed_count=:count,finished_at=NOW() WHERE id=:id")->execute(['count' => $count, 'id' => $logId]);
            $runs->succeed($runId, $count);
            return $count;
        } catch (Throwable $e) {
            $this->checkpoint($from, $to, null, 'error');
            if ($logId > 0) {
                $pdo->prepare("UPDATE meli_sync_logs SET status='error',processed_count=:count,error_message=:error,finished_at=NOW() WHERE id=:id")
                    ->execute(['count' => $count, 'error' => mb_substr($e->getMessage(), 0, 500), 'id' => $logId]);
            }
            $pdo->prepare("UPDATE meli_accounts SET status='error',last_error=:error WHERE id=:id")
                ->execute(['error' => mb_substr($e->getMessage(), 0, 500), 'id' => $this->accountId]);
            $runs->fail($runId, $count, $e->getMessage());
            throw $e;
        } finally {
            $lock->release($lockId);
        }
    }

    /** @param array<string,mixed> $meta */
    public function syncOrderById(
        int|string $externalOrderId,
        array $meta = [],
        ?callable $beforePersist = null
    ): int
    {
        $meta = array_replace([
            'job_type' => 'orders_sync',
            'source' => PHP_SAPI === 'cli' ? 'cron' : 'web',
            'bulk' => false,
        ], $meta);
        $order = $this->api->get('/orders/' . rawurlencode((string) $externalOrderId), [], $meta);
        return $this->persistOrder($order, true, $beforePersist);
    }

    /**
     * Persiste exactamente el snapshot remoto reclamado por Queue Core.
     *
     * Esta entrada no conserva compatibilidad con los productores legacy:
     * nunca crea trabajo V2/V3, reconciliaciones financieras ni
     * enriquecimientos, y tampoco ejecuta fallbacks remotos inline. Las
     * capacidades que aún pertenecen a B2 quedan registradas localmente como
     * obligaciones pendientes de Queue Core.
     *
     * @param array<string,mixed> $meta
     */
    public function syncOrderByIdForQueueCore(
        int|string $externalOrderId,
        array $meta = [],
        ?callable $beforePersist = null
    ): int {
        $meta = array_replace([
            'job_type' => 'order_exact',
            'source' => 'queue_core',
            'bulk' => false,
        ], $meta);
        $order = $this->api->get(
            '/orders/' . rawurlencode((string) $externalOrderId),
            [],
            $meta
        );
        return $this->persistOrder($order, false, $beforePersist, false);
    }

    /**
     * Entrada exacta del motor greenfield. No publica trabajo legacy ni
     * consulta Queue Core; persiste únicamente la orden solicitada.
     *
     * @param array<string,mixed> $meta
     */
    public function syncOrderByIdForQueueV4Clean(
        int|string $externalOrderId,
        array $meta = [],
        ?callable $beforePersist = null,
    ): int {
        $meta = array_replace([
            'job_type' => 'order_exact',
            'source' => 'queue_v4_clean',
            'bulk' => false,
        ], $meta);
        $order = $this->api->get(
            '/orders/' . rawurlencode((string) $externalOrderId),
            [],
            $meta,
        );
        return $this->persistOrder($order, false, $beforePersist, false, false);
    }

    /** Un paso web exacto: persiste la orden y no crea trabajo posterior. */
    public function syncOrderByIdForManual(
        int|string $externalOrderId,array $meta=[],?callable $beforePersist=null
    ): int {
        $meta=array_replace(['job_type'=>'order_exact','source'=>'manual_exact','bulk'=>false],$meta);
        $order=$this->api->get('/orders/'.rawurlencode((string)$externalOrderId),[],$meta);
        return $this->persistOrder($order,false,$beforePersist,false,false);
    }

    /** @param array<string,mixed> $meta */
    public function syncShipmentById(int|string $externalShipmentId, array $meta = []): int
    {
        $externalShipmentId = trim((string) $externalShipmentId);
        if ($externalShipmentId === '' || preg_match('/^[0-9]+$/', $externalShipmentId) !== 1) {
            throw new \InvalidArgumentException('Envío Mercado Libre inválido.');
        }
        $meta = array_replace([
            'job_type' => 'orders_event_sync',
            'source' => 'webhook_worker',
            'bulk' => false,
        ], $meta);
        $shipment = $this->api->get('/shipments/' . rawurlencode($externalShipmentId), [], $meta);
        $externalOrderId = $shipment['order_id']
            ?? ($shipment['order']['id'] ?? null)
            ?? ($shipment['orders'][0]['id'] ?? null);
        $orderId = null;
        if ($externalOrderId !== null && (string) $externalOrderId !== '') {
            $stmt = Database::connection()->prepare(
                'SELECT id FROM meli_orders WHERE meli_account_id=? AND external_order_id=? LIMIT 1'
            );
            $stmt->execute([$this->accountId, (string) $externalOrderId]);
            $found = $stmt->fetchColumn();
            if ($found !== false) {
                $orderId = (int) $found;
            } else {
                $orderId = $this->syncOrderById((string) $externalOrderId, $meta);
            }
        }
        if ($orderId === null) {
            $stmt = Database::connection()->prepare(
                'SELECT id FROM meli_orders WHERE meli_account_id=? AND external_shipping_id=? LIMIT 1'
            );
            $stmt->execute([$this->accountId, $externalShipmentId]);
            $found = $stmt->fetchColumn();
            if ($found !== false) {
                $orderId = (int) $found;
            }
        }
        $packId = null;
        $externalPackId = $shipment['pack_id'] ?? null;
        if ($externalPackId !== null) {
            $stmt = Database::connection()->prepare(
                'SELECT id FROM meli_packs WHERE meli_account_id=? AND external_pack_id=? LIMIT 1'
            );
            $stmt->execute([$this->accountId, (string) $externalPackId]);
            $found = $stmt->fetchColumn();
            if ($found !== false) {
                $packId = (int) $found;
            }
        }
        return $this->persistShipment($orderId, $packId, $shipment);
    }

    /**
     * Snapshot exacto de Queue Core: un GET y solo relaciones locales.
     *
     * @param array<string,mixed> $meta
     */
    public function syncShipmentByIdForQueueCore(int|string $externalShipmentId, array $meta = []): int
    {
        $externalShipmentId = trim((string) $externalShipmentId);
        if ($externalShipmentId === '' || !ctype_digit($externalShipmentId)) {
            throw new \InvalidArgumentException('Envío Mercado Libre inválido.');
        }
        $shipment = $this->api->get(
            '/shipments/' . rawurlencode($externalShipmentId),
            [],
            array_replace(['job_type' => 'webhook_shipment_exact', 'source' => 'queue_core_webhook'], $meta)
        );
        if ((string) ($shipment['id'] ?? '') !== $externalShipmentId) {
            throw new \RuntimeException('La respuesta del envío no coincide con el recurso solicitado.');
        }
        $externalOrderId = $shipment['order_id']
            ?? ($shipment['order']['id'] ?? null)
            ?? ($shipment['orders'][0]['id'] ?? null);
        $orderId = null;
        if ($externalOrderId !== null && ctype_digit((string) $externalOrderId)) {
            $order = Database::connection()->prepare(
                'SELECT id FROM meli_orders WHERE meli_account_id=? AND external_order_id=? LIMIT 1'
            );
            $order->execute([$this->accountId, (string) $externalOrderId]);
            $value = $order->fetchColumn();
            $orderId = $value !== false ? (int) $value : null;
        }
        $packId = null;
        $externalPackId = trim((string) ($shipment['pack_id'] ?? ''));
        if ($externalPackId !== '') {
            $pack = Database::connection()->prepare(
                'SELECT id FROM meli_packs WHERE meli_account_id=? AND external_pack_id=? LIMIT 1'
            );
            $pack->execute([$this->accountId, $externalPackId]);
            $value = $pack->fetchColumn();
            $packId = $value !== false ? (int) $value : null;
        }
        return $this->persistShipment($orderId, $packId, $shipment);
    }

    /**
     * Snapshot exacto de Queue Core: un GET y enlaces únicamente a órdenes locales.
     *
     * @param array<string,mixed> $meta
     */
    public function syncPackByIdForQueueCore(int|string $externalPackId, array $meta = []): int
    {
        $externalPackId = trim((string) $externalPackId);
        if ($externalPackId === '' || !ctype_digit($externalPackId)) {
            throw new \InvalidArgumentException('Paquete Mercado Libre inválido.');
        }
        $pack = $this->api->get(
            '/packs/' . rawurlencode($externalPackId),
            [],
            array_replace(['job_type' => 'webhook_pack_exact', 'source' => 'queue_core_webhook'], $meta)
        );
        if ((string) ($pack['id'] ?? '') !== $externalPackId) {
            throw new \RuntimeException('La respuesta del paquete no coincide con el recurso solicitado.');
        }
        $orderId = 0;
        foreach ((array) ($pack['orders'] ?? []) as $remoteOrder) {
            $externalOrderId = trim((string) ($remoteOrder['id'] ?? ''));
            if ($externalOrderId === '' || !ctype_digit($externalOrderId)) {
                continue;
            }
            $order = Database::connection()->prepare(
                'SELECT id FROM meli_orders WHERE meli_account_id=? AND external_order_id=? LIMIT 1'
            );
            $order->execute([$this->accountId, $externalOrderId]);
            $orderId = (int) ($order->fetchColumn() ?: 0);
            if ($orderId > 0) {
                break;
            }
        }
        return $this->persistPack($orderId, $pack);
    }

    public function syncRangeChunk(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        int $offset = 0,
        ?int $maxApiPages = null,
        bool $allowInlineEnrichment = true
    ): array
    {
        $pdo = Database::connection();
        $settings = new SyncSettingsService();
        $count = 0;
        $sellerId = $this->sellerId();
        $limit = $settings->pageLimit();
        $maxOrders = $settings->maxOrdersPerRun();
        $currentOffset = max(0, $offset);
        $total = null;
        $pages = 0;
        $maxApiPages = $settings->maxApiPagesPerRun($maxApiPages);
        do {
            $page = $this->api->get('/orders/search', [
                'seller' => $sellerId,
                'order.date_created.from' => $from->format(DATE_ATOM),
                'order.date_created.to' => $to->format(DATE_ATOM),
                'sort' => 'date_desc',
                'offset' => $currentOffset,
                'limit' => $limit,
            ], ['job_type' => 'orders_sync', 'source' => PHP_SAPI === 'cli' ? 'cron' : 'web', 'bulk' => true]);
            $pages++;
            $results = is_array($page['results'] ?? null) ? $page['results'] : [];
            $total = (int) ($page['paging']['total'] ?? 0);
            $processedFromPage = 0;
            foreach ($results as $order) {
                if (!empty($order['id'])) {
                    $this->persistOrder($order, $allowInlineEnrichment);
                    $count++;
                    $processedFromPage++;
                    if ($count >= $maxOrders) {
                        break;
                    }
                }
            }
            $currentOffset += $count >= $maxOrders ? $processedFromPage : count($results);
            $this->checkpoint($from, $to, (string) $currentOffset, $currentOffset >= $total ? 'complete' : 'running');
            if ($results !== [] && $currentOffset < $total && $count < $maxOrders && $settings->pauseMs() > 0) {
                usleep($settings->pauseMs() * 1000);
            }
        } while (
            $results !== []
            && $currentOffset < $total
            && $count < $maxOrders
            && $pages < $maxApiPages
        );

        $status = $currentOffset >= $total ? 'complete' : 'partial';
        $pdo->prepare("UPDATE meli_accounts SET last_sync_at=NOW(), status='conectado', last_error=NULL WHERE id=:id")
            ->execute(['id' => $this->accountId]);

        return [
            'processed' => $count,
            'offset_before' => max(0, $offset),
            'offset_after' => $currentOffset,
            'total' => $total,
            'status' => $status,
            'api_pages' => $pages,
            'limits' => [
                'orders' => $maxOrders,
                'api_pages' => $maxApiPages,
            ],
        ];
    }

    private function persistOrder(
        array $order,
        bool $allowInlineEnrichment = true,
        ?callable $beforePersist = null,
        bool $allowFollowUpFanout = true,
        bool $recordPendingCapabilities = true
    ): int
    {
        $pdo = Database::connection();
        $externalId = (string) $order['id'];
        $incomingUpdated = $this->dateNormalizer->normalize($order['last_updated'] ?? null, 'orders.last_updated');
        $rawPayload = json_encode(
            $order,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $schema = new SchemaInspectorService();
        $hasLastUpdatedUtc = $schema->hasColumn('meli_orders', 'last_updated_utc');
        $hasQueueSnapshot = $schema->hasColumn('meli_orders', 'queue_snapshot_version');
        $queueSnapshotVersion = hash('sha256', $rawPayload);
        $pdo->beginTransaction();
        try {
            if ($beforePersist !== null) {
                $beforePersist($pdo);
            }
            // The monotonic check and the write share the same row lock. Two
            // distinct input versions can no longer let an older API snapshot
            // overwrite a newer order after a concurrent worker commits.
            if ($hasLastUpdatedUtc || $hasQueueSnapshot) {
                $snapshotTimestampColumn = $hasLastUpdatedUtc ? 'last_updated_utc' : 'queue_snapshot_at';
                $current = $pdo->prepare(
                    'SELECT id,`' . $snapshotTimestampColumn . '` AS current_snapshot_at FROM meli_orders
                     WHERE meli_account_id=? AND external_order_id=? LIMIT 1 FOR UPDATE'
                );
                $current->execute([$this->accountId, $externalId]);
                $local = $current->fetch(PDO::FETCH_ASSOC);
                if (is_array($local) && !empty($local['current_snapshot_at'])
                    && !empty($incomingUpdated['utc'])
                    && strcmp((string) $local['current_snapshot_at'], (string) $incomingUpdated['utc']) > 0) {
                    $pdo->commit();
                    Logger::write('info', 'Snapshot de orden antiguo ignorado.', [
                        'account_id' => $this->accountId,
                        'external_order_id' => $externalId,
                    ]);
                    return (int) $local['id'];
                }
            }
            $created = $this->dateNormalizer->normalize($order['date_created'] ?? null, 'orders.date_created');
            $closed = $this->dateNormalizer->normalize($order['date_closed'] ?? null, 'orders.date_closed');
            $updated = $incomingUpdated;
            $columns = [
                'meli_account_id' => ':account',
                'external_order_id' => ':external',
                'external_pack_id' => ':pack',
                'date_created' => ':created',
                'date_closed' => ':closed',
                'status' => ':status',
                'status_detail' => ':detail',
                'total_amount' => ':total',
                'paid_amount' => ':paid',
                'currency_id' => ':currency',
                'buyer_id' => ':buyer_id',
                'buyer_nickname' => ':buyer_name',
                'external_shipping_id' => ':shipping',
                'tags_json' => ':tags',
                'raw_json' => ':raw',
                'raw_path' => ':raw_path',
                'synced_at' => 'NOW()',
            ];
            $params = [
                'account' => $this->accountId,
                'external' => $externalId,
                'pack' => $order['pack_id'] ?? null,
                'created' => $created['utc'],
                'closed' => $closed['utc'],
                'status' => $order['status'] ?? null,
                'detail' => $order['status_detail'] ?? null,
                'total' => $order['total_amount'] ?? 0,
                'paid' => $order['paid_amount'] ?? 0,
                'currency' => $order['currency_id'] ?? null,
                'buyer_id' => $order['buyer']['id'] ?? null,
                'buyer_name' => $order['buyer']['nickname'] ?? null,
                'shipping' => $order['shipping']['id'] ?? null,
                'tags' => json_encode($order['tags'] ?? [], JSON_UNESCAPED_UNICODE),
                'raw' => $rawPayload,
                'raw_path' => null,
            ];
            $updates = [
                'external_pack_id=VALUES(external_pack_id)',
                'date_created=VALUES(date_created)',
                'date_closed=VALUES(date_closed)',
                'status=VALUES(status)',
                'status_detail=VALUES(status_detail)',
                'total_amount=VALUES(total_amount)',
                'paid_amount=VALUES(paid_amount)',
                'currency_id=VALUES(currency_id)',
                'buyer_id=VALUES(buyer_id)',
                'buyer_nickname=VALUES(buyer_nickname)',
                'external_shipping_id=VALUES(external_shipping_id)',
                'tags_json=VALUES(tags_json)',
                'raw_json=VALUES(raw_json)',
                'raw_path=VALUES(raw_path)',
                'synced_at=NOW()',
                'id=LAST_INSERT_ID(id)',
            ];
            if ($hasQueueSnapshot) {
                $columns['queue_snapshot_version'] = ':queue_snapshot_version';
                $columns['queue_snapshot_at'] = ':queue_snapshot_at';
                $params['queue_snapshot_version'] = $queueSnapshotVersion;
                $params['queue_snapshot_at'] = $incomingUpdated['utc'] ?? null;
                $updates[] = 'queue_snapshot_version=VALUES(queue_snapshot_version)';
                $updates[] = 'queue_snapshot_at=VALUES(queue_snapshot_at)';
            }
            $this->addNormalizedColumns($columns, $params, $updates, 'meli_orders', 'date_created', $created);
            $this->addNormalizedColumns($columns, $params, $updates, 'meli_orders', 'date_closed', $closed);
            $this->addNormalizedColumns($columns, $params, $updates, 'meli_orders', 'last_updated', $updated);
            $stmt = $pdo->prepare(
                'INSERT INTO meli_orders (' . implode(',', array_keys($columns)) . ')
                 VALUES (' . implode(',', array_values($columns)) . ')
                 ON DUPLICATE KEY UPDATE ' . implode(',', $updates)
            );
            $stmt->execute($params);
            $orderId = (int) $pdo->lastInsertId();
            $this->persistItems($orderId, $order['order_items'] ?? []);
            if (!empty($order['pack_id'])) {
                $this->linkLocalPack($pdo, $orderId, (string) $order['pack_id'], $order['shipping']['id'] ?? null);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $this->externalizePayload('meli_orders', $orderId, $rawPayload);
        $this->externalizeOrderItems($orderId);
        foreach ($order['payments'] ?? [] as $payment) {
            if (!empty($payment['id'])) {
                try { $this->persistPayment($orderId, $payment); }
                catch (Throwable $e) {
                    if (!$allowFollowUpFanout) {
                        // Queue Core has a known GET response and can retry the
                        // idempotent local persistence safely. Never declare an
                        // order terminal while one of its payments is missing.
                        throw new \RuntimeException('Queue Core payment persistence is incomplete.', 0, $e);
                    }
                    Logger::write('warning', 'Pago pendiente de reintento.', [
                        'account_id'=>$this->accountId,
                        'payment_id'=>$payment['id'],
                        'error'=>$e->getMessage(),
                    ]);
                }
            }
        }
        if (!$allowFollowUpFanout) {
            if($recordPendingCapabilities){
                $this->recordQueueCorePendingCapability($orderId, 'financial_projection');
                if (!empty($order['pack_id']) || !empty($order['shipping']['id'])) {
                    $this->recordQueueCorePendingCapability($orderId, 'order_enrichment');
                }
            }
            return $orderId;
        }
        try {
            $projection = (new SaleFinancialStateService())->projectOrder($orderId);
            (new SaleFinancialService())->queueFromOrderId(
                $orderId,
                'order_persisted',
                $orderId,
                20,
                (string) ($projection['input_version'] ?? '')
            );
        } catch (Throwable $e) {
            Logger::write('warning', 'La orden quedó guardada, pero su proyección financiera V3 quedó pendiente.', [
                'account_id' => $this->accountId,
                'order_id' => $orderId,
                'error' => SafeErrorPresenter::message($e, 'Proyección financiera local pendiente.'),
            ]);
        }
        $shipmentId = !empty($order['shipping']['id']) ? (string) $order['shipping']['id'] : null;
        $packId = !empty($order['pack_id']) ? (string) $order['pack_id'] : null;
        $enrichment = new OrderEnrichmentService();
        if ($enrichment->isAvailable()) {
            $enrichment->enqueueOrder($this->accountId, $orderId, $packId, $shipmentId);
        } elseif ($allowInlineEnrichment) {
            $this->enrichInlineFallback($orderId, $packId, $shipmentId);
        }
        return $orderId;
    }

    private function recordQueueCorePendingCapability(int $orderId, string $capability): void
    {
        if ($orderId < 1 || !in_array($capability, ['financial_projection', 'order_enrichment'], true)) {
            return;
        }
        $pdo = Database::connection();
        $company = $pdo->prepare(
            'SELECT company_id FROM meli_accounts WHERE id=? LIMIT 1'
        );
        $company->execute([$this->accountId]);
        $companyId = (int) $company->fetchColumn();
        if ($companyId < 1) {
            throw new \RuntimeException('Queue Core could not confirm the order company scope.');
        }
        $schema = new SchemaInspectorService();
        if ($schema->hasColumn('queue_core_pending_capabilities', 'lifecycle_generation')
            && $schema->hasColumn('meli_orders', 'queue_snapshot_version')) {
            $snapshot = $pdo->prepare(
                'SELECT queue_snapshot_version FROM meli_orders
                 WHERE id=? AND meli_account_id=? LIMIT 1'
            );
            $snapshot->execute([$orderId, $this->accountId]);
            $version = trim((string) ($snapshot->fetchColumn() ?: ''));
            if ($version === '') {
                throw new \RuntimeException('Queue Core order snapshot version is unavailable.');
            }
            // A repeated observation of the same snapshot does not reopen a
            // resolved graph. A genuinely new snapshot advances the lifecycle
            // generation while retaining all previous dependency evidence.
            $pdo->prepare(
                'INSERT INTO queue_core_pending_capabilities
                    (company_id,meli_account_id,resource_type,resource_id,capability_key,state,input_version)
                 VALUES (?, ?, "order", ?, ?, "pending_b2", ?)
                 ON DUPLICATE KEY UPDATE
                    state=IF(input_version<=>VALUES(input_version),state,"pending_b2"),
                    lifecycle_generation=IF(input_version<=>VALUES(input_version),lifecycle_generation,lifecycle_generation+1),
                    required_dependencies=IF(input_version<=>VALUES(input_version),required_dependencies,0),
                    completed_dependencies=IF(input_version<=>VALUES(input_version),completed_dependencies,0),
                    last_error_class=IF(input_version<=>VALUES(input_version),last_error_class,NULL),
                    resolved_at=IF(input_version<=>VALUES(input_version),resolved_at,NULL),
                    input_version=VALUES(input_version),updated_at=UTC_TIMESTAMP(3)'
            )->execute([$companyId, $this->accountId, (string) $orderId, $capability, $version]);
            return;
        }
        $pdo->prepare(
            'INSERT INTO queue_core_pending_capabilities
                (company_id,meli_account_id,resource_type,resource_id,capability_key,state)
             VALUES (?, ?, "order", ?, ?, "pending_b2")
             ON DUPLICATE KEY UPDATE state="pending_b2",updated_at=UTC_TIMESTAMP(3)'
        )->execute([$companyId, $this->accountId, (string) $orderId, $capability]);
    }

    private function persistItems(int $orderId, array $items): void
    {
        $pdo = Database::connection();
        $this->unlinkReplacedOrderItemPayloads($pdo, $orderId);
        $pdo->prepare('DELETE FROM meli_order_items WHERE meli_order_id=:order')->execute(['order' => $orderId]);
        $stmt = $pdo->prepare('INSERT INTO meli_order_items (meli_order_id,meli_account_id,external_item_id,external_variation_id,title,seller_sku,quantity,unit_price,full_unit_price,sale_fee,listing_type_id,raw_json) VALUES (:order,:account,:item,:variation,:title,:sku,:quantity,:unit,:full,:fee,:listing,:raw)');
        foreach ($items as $row) {
            $item = $row['item'] ?? [];
            $stmt->execute([
                'order' => $orderId,
                'account' => $this->accountId,
                'item' => $item['id'] ?? 'unknown',
                'variation' => $item['variation_id'] ?? null,
                'title' => $item['title'] ?? 'Sin título',
                'sku' => $item['seller_sku'] ?? null,
                'quantity' => $row['quantity'] ?? 1,
                'unit' => $row['unit_price'] ?? 0,
                'full' => $row['full_unit_price'] ?? null,
                'fee' => $row['sale_fee'] ?? 0,
                'listing' => $item['listing_type_id'] ?? null,
                'raw' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
        }
    }

    private function unlinkReplacedOrderItemPayloads(PDO $pdo, int $orderId): void
    {
        if (!$this->payloadCatalogAvailable($pdo)) {
            return;
        }
        $objects = $pdo->prepare(
            'SELECT DISTINCT r.payload_object_id
             FROM remote_payload_references r
             INNER JOIN meli_order_items i
               ON i.id=r.entity_id
              AND r.entity_table="meli_order_items"
              AND r.meli_account_id=i.meli_account_id
             WHERE i.meli_order_id=:order_id
               AND i.meli_account_id=:account_id'
        );
        $objects->execute([
            'order_id' => $orderId,
            'account_id' => $this->accountId,
        ]);
        $objectIds = array_values(array_filter(
            array_map('intval', $objects->fetchAll(PDO::FETCH_COLUMN)),
            static fn (int $id): bool => $id > 0
        ));
        if ($objectIds === []) {
            return;
        }
        $pdo->prepare(
            'DELETE r
             FROM remote_payload_references r
             INNER JOIN meli_order_items i
               ON i.id=r.entity_id
              AND r.entity_table="meli_order_items"
              AND r.meli_account_id=i.meli_account_id
             WHERE i.meli_order_id=:order_id
               AND i.meli_account_id=:account_id'
        )->execute([
            'order_id' => $orderId,
            'account_id' => $this->accountId,
        ]);
        $pdo->exec(
            'UPDATE remote_payload_objects o
             SET reference_count=(
               SELECT COUNT(*) FROM remote_payload_references r
               WHERE r.payload_object_id=o.id
             )
             WHERE o.id IN (' . implode(',', $objectIds) . ')'
        );
    }

    private function persistPayment(int $orderId, array $payment): void
    {
        $paymentId = (string) $payment['id'];
        $needsExpansion = empty($payment['transaction_amount']) || empty($payment['date_approved']);
        $detailStatus = $needsExpansion ? 'partial' : 'summary';
        $detailAttempts = 0;
        $detailLastAttempt = null;
        $detailUnavailableAt = $needsExpansion ? gmdate('Y-m-d H:i:s') : null;
        $detailErrorCode = $needsExpansion ? 'embedded_summary_incomplete' : null;
        $detailErrorMessage = $needsExpansion
            ? 'Mercado Libre no entregó todos los campos en el pago embebido. No se consultó /payments/{id}.'
            : null;

        $approved = $this->dateNormalizer->normalize($payment['date_approved'] ?? null, 'payments.date_approved');
        $rawPayload = json_encode(
            $payment,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $columns = [
            'meli_account_id' => ':account',
            'meli_order_id' => ':order',
            'external_payment_id' => ':external',
            'status' => ':status',
            'status_detail' => ':detail',
            'payment_method_id' => ':method',
            'payment_type' => ':type',
            'transaction_amount' => ':amount',
            'shipping_cost' => ':shipping',
            'coupon_amount' => ':coupon',
            'total_paid_amount' => ':total',
            'marketplace_fee' => ':fee',
            'date_approved' => ':approved',
            'raw_json' => ':raw',
            'raw_path' => ':path',
            'synced_at' => 'NOW()',
        ];
        $params = [
            'account' => $this->accountId, 'order' => $orderId, 'external' => $paymentId, 'status' => $payment['status'] ?? null,
            'detail' => $payment['status_detail'] ?? null, 'method' => $payment['payment_method_id'] ?? null,
            'type' => $payment['payment_type'] ?? null, 'amount' => $payment['transaction_amount'] ?? 0,
            'shipping' => $payment['shipping_cost'] ?? 0, 'coupon' => $payment['coupon_amount'] ?? 0,
            'total' => $payment['total_paid_amount'] ?? 0, 'fee' => $payment['marketplace_fee'] ?? 0,
            'approved' => $approved['utc'],
            'raw' => $rawPayload, 'path' => null,
        ];
        $updates = [
            'meli_order_id=VALUES(meli_order_id)',
            'status=VALUES(status)',
            'status_detail=VALUES(status_detail)',
            'payment_method_id=VALUES(payment_method_id)',
            'payment_type=VALUES(payment_type)',
            'transaction_amount=VALUES(transaction_amount)',
            'shipping_cost=VALUES(shipping_cost)',
            'coupon_amount=VALUES(coupon_amount)',
            'total_paid_amount=VALUES(total_paid_amount)',
            'marketplace_fee=VALUES(marketplace_fee)',
            'date_approved=VALUES(date_approved)',
            'raw_json=VALUES(raw_json)',
            'raw_path=VALUES(raw_path)',
            'synced_at=NOW()',
            'id=LAST_INSERT_ID(id)',
        ];
        $schema = new SchemaInspectorService();
        $hasDetailStatusColumn = $schema->hasColumn('meli_payments', 'detail_status');
        $this->addNormalizedColumns($columns, $params, $updates, 'meli_payments', 'date_approved', $approved);
        foreach ([
            'detail_status' => [$detailStatus, 'detail_status=VALUES(detail_status)'],
            'detail_attempts' => [$detailAttempts, 'detail_attempts=VALUES(detail_attempts)'],
            'detail_last_attempt_at' => [$detailLastAttempt, 'detail_last_attempt_at=COALESCE(VALUES(detail_last_attempt_at),detail_last_attempt_at)'],
            'detail_unavailable_at' => [$detailUnavailableAt, $hasDetailStatusColumn ? "detail_unavailable_at=CASE WHEN VALUES(detail_status)='unavailable' THEN COALESCE(detail_unavailable_at,VALUES(detail_unavailable_at),NOW()) ELSE detail_unavailable_at END" : 'detail_unavailable_at=VALUES(detail_unavailable_at)'],
            'detail_error_code' => [$detailErrorCode, 'detail_error_code=VALUES(detail_error_code)'],
            'detail_error_message' => [$detailErrorMessage, 'detail_error_message=VALUES(detail_error_message)'],
        ] as $column => [$value, $update]) {
            if ($schema->hasColumn('meli_payments', $column)) {
                $columns[$column] = ':' . $column;
                $params[$column] = $value;
                $updates[] = $update;
            }
        }
        $stmt = Database::connection()->prepare(
            'INSERT INTO meli_payments (' . implode(',', array_keys($columns)) . ')
             VALUES (' . implode(',', array_values($columns)) . ')
             ON DUPLICATE KEY UPDATE ' . implode(',', $updates)
        );
        $stmt->execute($params);
        $paymentRowId = (int) Database::connection()->lastInsertId();
        if ($paymentRowId > 0) {
            $this->externalizePayload('meli_payments', $paymentRowId, $rawPayload);
        }
    }

    private function persistShipment(?int $orderId, ?int $packId, array $shipment): int
    {
        $rawPayload = json_encode(
            $shipment,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $cost = $shipment['shipping_option']['cost'] ?? $shipment['cost_components']['gross_amount'] ?? 0;
        $stmt = Database::connection()->prepare('INSERT INTO meli_shipments (meli_account_id,external_shipment_id,meli_order_id,meli_pack_id,status,substatus,logistic_type,shipping_mode,tracking_number,carrier,estimated_delivery,gross_cost,seller_cost,buyer_cost,discounts,raw_json,raw_path,synced_at) VALUES (:account,:external,:order,:pack,:status,:substatus,:logistic,:mode,:tracking,:carrier,:estimated,:gross,:seller,:buyer,:discounts,:raw,:path,NOW()) ON DUPLICATE KEY UPDATE meli_order_id=COALESCE(VALUES(meli_order_id),meli_order_id),meli_pack_id=COALESCE(VALUES(meli_pack_id),meli_pack_id),status=VALUES(status),substatus=VALUES(substatus),logistic_type=VALUES(logistic_type),shipping_mode=VALUES(shipping_mode),tracking_number=VALUES(tracking_number),carrier=VALUES(carrier),estimated_delivery=VALUES(estimated_delivery),gross_cost=VALUES(gross_cost),seller_cost=VALUES(seller_cost),buyer_cost=VALUES(buyer_cost),discounts=VALUES(discounts),raw_json=VALUES(raw_json),raw_path=VALUES(raw_path),synced_at=NOW(),id=LAST_INSERT_ID(id)');
        $stmt->execute([
            'account' => $this->accountId, 'external' => $shipment['id'], 'order' => $orderId, 'pack' => $packId,
            'status' => $shipment['status'] ?? null, 'substatus' => $shipment['substatus'] ?? null,
            'logistic' => $shipment['logistic_type'] ?? null, 'mode' => $shipment['mode'] ?? null,
            'tracking' => $shipment['tracking_number'] ?? null, 'carrier' => $shipment['tracking_method'] ?? null,
            'estimated' => $this->date($shipment['shipping_option']['estimated_delivery_time']['date'] ?? null),
            'gross' => $cost, 'seller' => $shipment['shipping_option']['list_cost'] ?? 0,
            'buyer' => $shipment['shipping_option']['cost'] ?? 0, 'discounts' => 0,
            'raw' => $rawPayload, 'path' => null,
        ]);
        $shipmentRowId = (int) Database::connection()->lastInsertId();
        if ($shipmentRowId > 0) {
            $this->externalizePayload('meli_shipments', $shipmentRowId, $rawPayload);
        }
        return $shipmentRowId;
    }

    private function persistPack(int $orderId, array $pack): int
    {
        $rawPayload = json_encode(
            $pack,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO meli_packs (meli_account_id,external_pack_id,external_shipment_id,buyer_json,status,raw_json,raw_path,synced_at) VALUES (:account,:external,:shipment,:buyer,:status,:raw,:path,NOW()) ON DUPLICATE KEY UPDATE external_shipment_id=VALUES(external_shipment_id),buyer_json=VALUES(buyer_json),status=VALUES(status),raw_json=VALUES(raw_json),raw_path=VALUES(raw_path),synced_at=NOW(),id=LAST_INSERT_ID(id)');
        $stmt->execute([
            'account' => $this->accountId, 'external' => $pack['id'], 'shipment' => $pack['shipment']['id'] ?? null,
            'buyer' => json_encode($pack['buyer'] ?? [], JSON_UNESCAPED_UNICODE), 'status' => $pack['status'] ?? null,
            'raw' => $rawPayload, 'path' => null,
        ]);
        $packId = (int) $pdo->lastInsertId();
        if ($packId > 0) {
            $this->externalizePayload('meli_packs', $packId, $rawPayload);
        }
        if ($orderId > 0) {
            $pdo->prepare('INSERT IGNORE INTO meli_pack_orders (meli_pack_id,meli_order_id) VALUES (:pack,:order)')
                ->execute(['pack' => $packId, 'order' => $orderId]);
        }
        $expected = [];
        foreach ((array) ($pack['orders'] ?? []) as $packOrder) {
            $externalOrderId = (string) ($packOrder['id'] ?? '');
            if ($externalOrderId === '') {
                continue;
            }
            $expected[] = $externalOrderId;
            $local = $pdo->prepare(
                'SELECT id FROM meli_orders WHERE meli_account_id=? AND external_order_id=? LIMIT 1'
            );
            $local->execute([$this->accountId, $externalOrderId]);
            $localId = (int) ($local->fetchColumn() ?: 0);
            if ($localId > 0) {
                $pdo->prepare('INSERT IGNORE INTO meli_pack_orders (meli_pack_id,meli_order_id) VALUES (?,?)')
                    ->execute([$packId, $localId]);
            }
        }
        if ((new SchemaInspectorService())->hasColumn('meli_packs', 'expected_orders_json')) {
            sort($expected, SORT_STRING);
            $linked = $pdo->prepare('SELECT COUNT(*) FROM meli_pack_orders WHERE meli_pack_id=?');
            $linked->execute([$packId]);
            $linkedCount = (int) $linked->fetchColumn();
            $status = $expected !== [] && $linkedCount === count($expected) ? 'complete' : 'partial';
            $pdo->prepare(
                'UPDATE meli_packs
                 SET expected_orders_count=?,linked_orders_count=?,expected_orders_json=?,
                     orders_fingerprint=?,integrity_status=?,integrity_message=?,verified_at=UTC_TIMESTAMP()
                 WHERE id=?'
            )->execute([
                count($expected), $linkedCount, json_encode($expected, JSON_UNESCAPED_UNICODE),
                hash('sha256', implode('|', $expected)), $status,
                $status === 'complete'
                    ? 'Todas las órdenes esperadas están enlazadas.'
                    : 'Faltan órdenes del paquete por recuperar.',
                $packId,
            ]);
        }
        return $packId;
    }

    private function externalizeOrderItems(int $orderId): void
    {
        $stmt = Database::connection()->prepare(
            'SELECT id,raw_json
             FROM meli_order_items
             WHERE meli_order_id=:order_id AND meli_account_id=:account_id
               AND raw_json IS NOT NULL AND raw_json<>""
             ORDER BY id'
        );
        $stmt->execute([
            'order_id' => $orderId,
            'account_id' => $this->accountId,
        ]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $this->externalizePayload(
                'meli_order_items',
                (int) $row['id'],
                (string) $row['raw_json']
            );
        }
    }

    private function externalizePayload(string $table, int $rowId, string $payload): void
    {
        if (
            $rowId <= 0
            || $payload === ''
            || !$this->payloadCatalogAvailable(Database::connection())
        ) {
            return;
        }
        try {
            $reference = (new FileRemotePayloadStore())->persist(
                $payload,
                new PayloadContext($table, $rowId, $this->accountId)
            );
            $clearColumns = $table === 'meli_order_items'
                ? 'raw_json=NULL'
                : 'raw_json=NULL,raw_path=NULL';
            $stmt = Database::connection()->prepare(
                'UPDATE `' . $table . '`
                 SET ' . $clearColumns . '
                 WHERE id=:id AND meli_account_id=:account_id
                   AND raw_json IS NOT NULL
                   AND SHA2(raw_json,256)=:payload_hash'
            );
            $stmt->execute([
                'id' => $rowId,
                'account_id' => $this->accountId,
                'payload_hash' => $reference->sha256,
            ]);
        } catch (Throwable) {
            // La fila normalizada ya quedó confirmada. Mantener raw_json como
            // fallback permite que el saneamiento reintente sin perder datos.
            Logger::write('warning', 'Payload pendiente de externalización local.', [
                'account_id' => $this->accountId,
                'entity_table' => $table,
                'entity_id' => $rowId,
            ]);
        }
    }

    private function payloadCatalogAvailable(PDO $pdo): bool
    {
        if ($this->payloadCatalogAvailable !== null) {
            return $this->payloadCatalogAvailable;
        }
        $tables = (new InformationSchemaGateway($pdo))->tablesExist([
            'remote_payload_objects',
            'remote_payload_references',
        ]);
        return $this->payloadCatalogAvailable =
            ($tables['remote_payload_objects'] ?? false)
            && ($tables['remote_payload_references'] ?? false);
    }

    private function linkLocalPack(PDO $pdo, int $orderId, string $externalPackId, mixed $externalShipmentId): void
    {
        $columnsAvailable = (new SchemaInspectorService())->hasColumn('meli_packs', 'integrity_status');
        $sql = $columnsAvailable
            ? 'INSERT INTO meli_packs
                (meli_account_id,external_pack_id,external_shipment_id,status,integrity_status,
                 linked_orders_count,integrity_message,synced_at)
               VALUES (?, ?, ?, "provisional", "provisional", 1,
                 "Relación creada con la orden recibida; falta verificar el paquete.", UTC_TIMESTAMP())
               ON DUPLICATE KEY UPDATE
                 external_shipment_id=COALESCE(external_shipment_id,VALUES(external_shipment_id)),
                 id=LAST_INSERT_ID(id)'
            : 'INSERT INTO meli_packs
                (meli_account_id,external_pack_id,external_shipment_id,status,synced_at)
               VALUES (?, ?, ?, "provisional", UTC_TIMESTAMP())
               ON DUPLICATE KEY UPDATE
                 external_shipment_id=COALESCE(external_shipment_id,VALUES(external_shipment_id)),
                 id=LAST_INSERT_ID(id)';
        $pdo->prepare($sql)->execute([$this->accountId, $externalPackId, $externalShipmentId]);
        $packId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT IGNORE INTO meli_pack_orders (meli_pack_id,meli_order_id) VALUES (?,?)')
            ->execute([$packId, $orderId]);
        if ($columnsAvailable) {
            $pdo->prepare(
                'UPDATE meli_packs
                 SET linked_orders_count=(SELECT COUNT(*) FROM meli_pack_orders WHERE meli_pack_id=?)
                 WHERE id=?'
            )->execute([$packId, $packId]);
        }
    }

    /**
     * Procesa exactamente un recurso deduplicado de la cola de enriquecimiento.
     *
     * @param array<string,mixed> $job
     * @return array{resource_type:string,external_resource_id:string,spawned_shipment_id:?string}
     */
    public function processEnrichmentResource(array $job): array
    {
        $orderId = (int) ($job['meli_order_id'] ?? 0);
        $type = (string) ($job['resource_type'] ?? '');
        $externalId = (string) ($job['external_resource_id'] ?? '');
        if ($orderId <= 0 || $externalId === '' || !in_array($type, ['pack', 'shipment'], true)) {
            throw new \InvalidArgumentException('Trabajo de enriquecimiento inválido.');
        }

        if ($type === 'pack') {
            $pack = $this->cachedResource('/packs/' . rawurlencode($externalId), $this->enrichmentMetadata($job));
            $this->persistPack($orderId, $pack);
            $shipmentId = !empty($pack['shipment']['id']) ? (string) $pack['shipment']['id'] : null;
            return ['resource_type' => $type, 'external_resource_id' => $externalId, 'spawned_shipment_id' => $shipmentId];
        }

        $shipment = $this->cachedResource('/shipments/' . rawurlencode($externalId), $this->enrichmentMetadata($job));
        $packId = null;
        $stmt = Database::connection()->prepare(
            'SELECT id FROM meli_packs WHERE meli_account_id=:account AND external_shipment_id=:shipment LIMIT 1'
        );
        $stmt->execute(['account' => $this->accountId, 'shipment' => $externalId]);
        $localPackId = $stmt->fetchColumn();
        if ($localPackId !== false) {
            $packId = (int) $localPackId;
        }
        $this->persistShipment($orderId, $packId, $shipment);
        return ['resource_type' => $type, 'external_resource_id' => $externalId, 'spawned_shipment_id' => null];
    }

    private function enrichInlineFallback(int $orderId, ?string $packExternalId, ?string $shipmentExternalId): void
    {
        try {
            if ($packExternalId !== null) {
                $pack = $this->cachedResource('/packs/' . rawurlencode($packExternalId));
                $this->persistPack($orderId, $pack);
                if (!empty($pack['shipment']['id'])) {
                    $shipmentExternalId = (string) $pack['shipment']['id'];
                }
            }
            if ($shipmentExternalId !== null) {
                $shipment = $this->cachedResource('/shipments/' . rawurlencode($shipmentExternalId));
                $this->persistShipment($orderId, null, $shipment);
            }
        } catch (Throwable $e) {
            Logger::write('warning', 'Enriquecimiento de orden pendiente de reintento.', [
                'account_id' => $this->accountId,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string,mixed>
     */
    /** @param array<string,mixed> $metadata */
    private function cachedResource(string $path, array $metadata = []): array
    {
        if (!isset($this->resourceCache[$path])) {
            $this->resourceCache[$path] = $this->api->get($path, [], $metadata + [
                'job_type' => 'orders_sync',
                'source' => PHP_SAPI === 'cli' ? 'cron' : 'web',
            ]);
        }
        return $this->resourceCache[$path];
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    private function enrichmentMetadata(array $job): array
    {
        return [
            'job_type' => 'order_enrichment',
            'source_queue_key' => 'order_enrichment',
            'source_work_id' => (string) ((int) ($job['id'] ?? 0)),
            'operation_key' => (string) ($job['resource_type'] ?? 'resource'),
            'source' => PHP_SAPI === 'cli' ? 'cron' : 'manual_exact',
        ];
    }

    private function checkpoint(DateTimeImmutable $from, DateTimeImmutable $to, ?string $cursor, string $status): void
    {
        Database::connection()->prepare('INSERT INTO meli_sync_checkpoints (meli_account_id,sync_type,cursor_value,range_from,range_to,status) VALUES (:account,\'orders\',:cursor,:from,:to,:status) ON DUPLICATE KEY UPDATE cursor_value=VALUES(cursor_value),range_from=VALUES(range_from),range_to=VALUES(range_to),status=VALUES(status)')
            ->execute(['account' => $this->accountId, 'cursor' => $cursor, 'from' => $from->format('Y-m-d H:i:s'), 'to' => $to->format('Y-m-d H:i:s'), 'status' => $status]);
    }

    private function sellerId(): int
    {
        $stmt = Database::connection()->prepare('SELECT meli_user_id FROM meli_accounts WHERE id=:id');
        $stmt->execute(['id' => $this->accountId]);
        return (int) $stmt->fetchColumn();
    }

    private function date(mixed $value): ?string
    {
        return $this->dateNormalizer->utc($value);
    }

    /**
     * @param array<string,string> $columns
     * @param array<string,mixed> $params
     * @param list<string> $updates
     * @param array<string,mixed> $normalized
     */
    private function addNormalizedColumns(array &$columns, array &$params, array &$updates, string $table, string $prefix, array $normalized): void
    {
        $schema = new SchemaInspectorService();
        $map = [
            $prefix . '_raw' => $normalized['raw_value'] ?? null,
            $prefix . '_utc' => $normalized['utc'] ?? null,
            $prefix . '_local' => $normalized['local'] ?? null,
            $prefix . '_local_date' => $normalized['local_date'] ?? null,
            $prefix . '_offset' => $normalized['source_offset'] ?? null,
        ];
        foreach ($map as $column => $value) {
            if (!$schema->hasColumn($table, $column)) {
                continue;
            }
            $placeholder = str_replace($prefix . '_', $prefix . '_norm_', $column);
            $columns[$column] = ':' . $placeholder;
            $params[$placeholder] = $value;
            $updates[] = $column . '=VALUES(' . $column . ')';
        }
    }
}
