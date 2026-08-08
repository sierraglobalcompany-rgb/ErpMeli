<?php

declare(strict_types=1);

namespace App\Modules\MeliGrowth\Services;

use App\Core\Database;
use App\Modules\Shared\Gateways\CoreReadGateway;
use App\Modules\Shared\Services\MeliReadGateway;
use App\Services\AppSettingsService;
use App\Services\MeliApiException;
use App\Services\MeliEndpointRegistry;
use PDO;
use Throwable;

final class GrowthSyncService
{
    private const STAGES = ['promotions', 'visits', 'trends', 'highlights', 'conversion'];

    public function __construct(
        private readonly ?MeliReadGateway $remote = null,
        private readonly ?CoreReadGateway $core = null,
    ) {
    }

    /** @param array<string,mixed> $job @return array<string,mixed> */
    public function process(array $job): array
    {
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        if ((string) ($job['job_type'] ?? '') === 'event_sync') {
            return $this->processEvent((int) ($job['meli_account_id'] ?? 0), $payload);
        }

        $core = $this->core ?? new CoreReadGateway();
        $requestedAccount = max(0, (int) ($job['meli_account_id'] ?? 0));
        $accounts = $core->activeAccounts($requestedAccount > 0 ? $requestedAccount : null);
        if ($accounts === []) {
            return ['processed' => 0, 'errors' => 0, 'status' => 'completed', 'stage' => 'no_accounts'];
        }

        $checkpoint = is_array($job['checkpoint'] ?? null) ? $job['checkpoint'] : [];
        $accountOffset = $requestedAccount > 0 ? 0 : max(0, (int) ($checkpoint['account_offset'] ?? 0));
        $stageIndex = max(0, (int) ($checkpoint['stage_index'] ?? 0));
        $account = $accounts[$accountOffset] ?? null;
        if (!is_array($account)) {
            return ['processed' => 0, 'errors' => 0, 'status' => 'completed', 'stage' => 'completed'];
        }

        $stage = self::STAGES[$stageIndex] ?? null;
        if ($stage === null) {
            if ($requestedAccount < 1 && isset($accounts[$accountOffset + 1])) {
                return $this->continueAt($accountOffset + 1, 0, count($accounts), 'next_account', 5);
            }
            return ['processed' => 0, 'errors' => 0, 'status' => 'completed', 'stage' => 'completed'];
        }

        $endpointConfirmed = $this->stageEndpointConfirmed($stage, $account);
        try {
            $result = $endpointConfirmed
                ? $this->{$stage}((int) $account['id'], $account)
                : ['processed' => 0];
        } catch (MeliApiException $error) {
            return $this->remoteFailure((int) $account['id'], $stage, $error, $accountOffset, $stageIndex, count($accounts));
        } catch (Throwable) {
            return [
                'processed' => 0,
                'errors' => 1,
                'status' => 'retry',
                'stage' => $stage,
                'safe_message' => 'El módulo no pudo completar esta etapa. El núcleo del ERP no fue afectado.',
                'delay_seconds' => 300,
                'checkpoint' => ['account_offset' => $accountOffset, 'stage_index' => $stageIndex],
                'progress_current' => ($accountOffset * count(self::STAGES)) + $stageIndex,
                'progress_total' => count($accounts) * count(self::STAGES),
            ];
        }

        $this->markCapability(
            (int) $account['id'],
            $stage,
            $endpointConfirmed ? 'supported' : 'investigating',
            null,
            $endpointConfirmed
                ? 'Información disponible para esta cuenta.'
                : 'Etapa omitida: el endpoint todavía no está confirmado en el mapa API local.'
        );
        $nextStage = $stageIndex + 1;
        $progress = ($accountOffset * count(self::STAGES)) + $nextStage;
        $total = count($accounts) * count(self::STAGES);
        if ($nextStage < count(self::STAGES)) {
            return [
                'processed' => (int) ($result['processed'] ?? 0),
                'errors' => 0,
                'status' => 'pending',
                'stage' => self::STAGES[$nextStage],
                'delay_seconds' => 5,
                'checkpoint' => ['account_offset' => $accountOffset, 'stage_index' => $nextStage],
                'progress_current' => $progress,
                'progress_total' => $total,
            ];
        }
        if ($requestedAccount < 1 && isset($accounts[$accountOffset + 1])) {
            return $this->continueAt($accountOffset + 1, 0, count($accounts), 'next_account', 5, (int) ($result['processed'] ?? 0));
        }

        return [
            'processed' => (int) ($result['processed'] ?? 0),
            'errors' => 0,
            'status' => 'completed',
            'stage' => 'completed',
            'progress_current' => $total,
            'progress_total' => $total,
        ];
    }

    /** @param array<string,mixed> $account @return array{processed:int} */
    private function promotions(int $accountId, array $account): array
    {
        if ($this->isFresh('ml_growth_promotions', 'meli_account_id', $accountId, 'observed_at', 6)) {
            return ['processed' => 0];
        }
        $payload = $this->gateway()->get(
            'meli-growth',
            $accountId,
            '/seller-promotions/users/' . (int) $account['meli_user_id'],
            ['app_version' => 'v2'],
            'growth_promotions'
        );
        $rows = $payload['results'] ?? $payload;
        $count = 0;
        foreach (is_array($rows) ? array_slice($rows, 0, 100) : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $externalId = trim((string) ($row['id'] ?? $row['promotion_id'] ?? ''));
            if ($externalId === '') {
                continue;
            }
            Database::connection()->prepare(
                'INSERT INTO ml_growth_promotions
                 (meli_account_id,external_promotion_id,name,promotion_type,status,start_at,end_at,snapshot_json,observed_at)
                 VALUES (?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE name=VALUES(name),promotion_type=VALUES(promotion_type),status=VALUES(status),
                 start_at=VALUES(start_at),end_at=VALUES(end_at),snapshot_json=VALUES(snapshot_json),observed_at=UTC_TIMESTAMP()'
            )->execute([
                $accountId,
                $externalId,
                $row['name'] ?? $row['title'] ?? 'Promoción sin nombre',
                $row['type'] ?? $row['promotion_type'] ?? null,
                $this->scalarStatus($row['status'] ?? null),
                $row['start_date'] ?? null,
                $row['finish_date'] ?? $row['end_date'] ?? null,
                $this->json($row),
            ]);
            $count++;
        }
        return ['processed' => $count];
    }

    /** @param array<string,mixed> $account */
    private function stageEndpointConfirmed(string $stage, array $account): bool
    {
        $siteId = strtoupper((string) ($account['site_id'] ?? 'MCO'));
        $sellerId = max(1, (int) ($account['meli_user_id'] ?? 0));
        $path = match ($stage) {
            'promotions' => '/seller-promotions/users/' . $sellerId,
            'visits' => '/users/' . $sellerId . '/items_visits/time_window',
            'trends' => '/trends/' . $siteId,
            'highlights' => '/highlights/' . $siteId . '/category/MCO1',
            'conversion' => null,
            default => null,
        };
        return $path === null || MeliEndpointRegistry::isConfirmed('GET', $path);
    }

    /** @param array<string,mixed> $account @return array{processed:int} */
    private function visits(int $accountId, array $account): array
    {
        if ($this->isFresh('ml_growth_account_visits_daily', 'meli_account_id', $accountId, 'observed_at', 1)) {
            return ['processed' => 0];
        }
        $days = max(1, min(150, (new AppSettingsService())->int('growth.sync_window_days', 30)));
        $payload = $this->gateway()->get(
            'meli-growth',
            $accountId,
            '/users/' . (int) $account['meli_user_id'] . '/items_visits/time_window',
            ['last' => $days, 'unit' => 'day'],
            'growth_visits'
        );
        $count = 0;
        foreach ((array) ($payload['results'] ?? []) as $row) {
            if (!is_array($row) || !isset($row['date'])) {
                continue;
            }
            $observedOn = substr((string) $row['date'], 0, 10);
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $observedOn) !== 1) {
                continue;
            }
            Database::connection()->prepare(
                'INSERT INTO ml_growth_account_visits_daily
                 (meli_account_id,observed_on,visits,source_from,source_to,observed_at)
                 VALUES (?,?,?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE visits=VALUES(visits),source_from=VALUES(source_from),
                 source_to=VALUES(source_to),observed_at=UTC_TIMESTAMP()'
            )->execute([
                $accountId,
                $observedOn,
                max(0, (int) ($row['total'] ?? 0)),
                $payload['date_from'] ?? null,
                $payload['date_to'] ?? null,
            ]);
            $count++;
        }
        return ['processed' => $count];
    }

    /** @param array<string,mixed> $account @return array{processed:int} */
    private function trends(int $accountId, array $account): array
    {
        $siteId = strtoupper((string) ($account['site_id'] ?? 'MCO'));
        $hours = max(24, (new AppSettingsService())->int('growth.trends_refresh_hours', 168));
        if ($this->isFresh('ml_growth_trends', 'site_id', $siteId, 'observed_at', $hours)) {
            return ['processed' => 0];
        }
        $payload = $this->gateway()->get('meli-growth', $accountId, '/trends/' . $siteId, [], 'growth_trends');
        $rows = $payload['results'] ?? $payload;
        $count = 0;
        foreach (is_array($rows) ? array_slice($rows, 0, 50) : [] as $position => $row) {
            $keyword = is_array($row) ? (string) ($row['keyword'] ?? $row['name'] ?? '') : (string) $row;
            if (trim($keyword) === '') {
                continue;
            }
            Database::connection()->prepare(
                'INSERT INTO ml_growth_trends (site_id,category_id,keyword,rank_position,snapshot_json,observed_at)
                 VALUES (?,?,?,?,?,UTC_TIMESTAMP())'
            )->execute([$siteId, 'ALL', trim($keyword), $position + 1, $this->json(is_array($row) ? $row : ['keyword' => $keyword])]);
            $count++;
        }
        return ['processed' => $count];
    }

    /** @param array<string,mixed> $account @return array{processed:int} */
    private function highlights(int $accountId, array $account): array
    {
        $categories = ($this->core ?? new CoreReadGateway())->topCategories($accountId, 1);
        $categoryId = (string) ($categories[0]['category_id'] ?? '');
        if ($categoryId === '') {
            $this->markCapability($accountId, 'highlights', 'expected_absence', null, 'No hay una categoría local suficiente para consultar destacados.');
            return ['processed' => 0];
        }
        $siteId = strtoupper((string) ($account['site_id'] ?? 'MCO'));
        $hours = max(24, (new AppSettingsService())->int('growth.highlights_refresh_hours', 168));
        if ($this->isFresh('ml_growth_highlights', 'site_id', $siteId, 'observed_at', $hours, 'category_id', $categoryId)) {
            return ['processed' => 0];
        }
        $payload = $this->gateway()->get(
            'meli-growth',
            $accountId,
            '/highlights/' . $siteId . '/category/' . rawurlencode($categoryId),
            [],
            'growth_highlights'
        );
        $rows = $payload['content'] ?? $payload['results'] ?? $payload;
        $count = 0;
        foreach (is_array($rows) ? array_slice($rows, 0, 20) : [] as $position => $row) {
            if (!is_array($row)) {
                continue;
            }
            Database::connection()->prepare(
                'INSERT INTO ml_growth_highlights
                 (site_id,category_id,external_item_id,external_product_id,rank_position,snapshot_json,observed_at)
                 VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())'
            )->execute([
                $siteId,
                $categoryId,
                $row['id'] ?? $row['item_id'] ?? null,
                $row['product_id'] ?? null,
                (int) ($row['position'] ?? ($position + 1)),
                $this->json($row),
            ]);
            $count++;
        }
        return ['processed' => $count];
    }

    /** @param array<string,mixed> $account @return array{processed:int} */
    private function conversion(int $accountId, array $account): array
    {
        $days = max(1, min(150, (new AppSettingsService())->int('growth.sync_window_days', 30)));
        $from = gmdate('Y-m-d 00:00:00', strtotime('-' . ($days - 1) . ' days'));
        $to = gmdate('Y-m-d 00:00:00', strtotime('+1 day'));
        $ordersByDay = [];
        foreach (($this->core ?? new CoreReadGateway())->dailyOrderTotals($accountId, $from, $to) as $row) {
            $ordersByDay[$row['observed_on']] = $row;
        }
        $stmt = Database::connection()->prepare(
            'SELECT observed_on,visits FROM ml_growth_account_visits_daily
             WHERE meli_account_id=? AND observed_on>=DATE(?) AND observed_on<DATE(?) ORDER BY observed_on'
        );
        $stmt->execute([$accountId, $from, $to]);
        $count = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $visit) {
            $date = (string) $visit['observed_on'];
            $orders = (int) ($ordersByDay[$date]['orders_count'] ?? 0);
            $visits = max(0, (int) $visit['visits']);
            $rate = $visits > 0 ? ($orders / $visits) * 100 : null;
            Database::connection()->prepare(
                'INSERT INTO ml_growth_conversion_daily
                 (meli_account_id,observed_on,visits,orders_count,conversion_rate,source_status)
                 VALUES (?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE visits=VALUES(visits),orders_count=VALUES(orders_count),
                 conversion_rate=VALUES(conversion_rate),source_status=VALUES(source_status)'
            )->execute([$accountId, $date, $visits, $orders, $rate, 'remote_visits_local_orders']);
            $count++;
        }
        return ['processed' => $count];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function processEvent(int $accountId, array $payload): array
    {
        if ($accountId < 1) {
            return ['processed' => 0, 'errors' => 1, 'status' => 'completed', 'stage' => 'account_unresolved'];
        }
        $topic = (string) ($payload['topic'] ?? '');
        $resourceId = trim((string) ($payload['resource_id'] ?? ''));
        if ($topic === 'promotion_candidate' && preg_match('/^CANDIDATE-[A-Z]{2,4}\d+-\d+$/i', $resourceId) === 1) {
            $path = '/seller-promotions/candidates/' . $resourceId;
            if (!MeliEndpointRegistry::isConfirmed('GET', $path)) {
                return $this->unsupportedEvent($accountId, 'promotion_candidate');
            }
            return $this->syncCandidate($accountId, $resourceId);
        }
        if ($topic === 'promotion_offer' && preg_match('/^OFFER-[A-Z]{2,4}\d+-\d+$/i', $resourceId) === 1) {
            $path = '/seller-promotions/offers/' . $resourceId;
            if (!MeliEndpointRegistry::isConfirmed('GET', $path)) {
                return $this->unsupportedEvent($accountId, 'promotion_offer');
            }
            return $this->syncOffer($accountId, $resourceId);
        }
        return ['processed' => 0, 'errors' => 0, 'status' => 'completed', 'stage' => 'event_not_applicable'];
    }

    /** @return array<string,mixed> */
    private function unsupportedEvent(int $accountId, string $capability): array
    {
        return [
            'account_id' => $accountId,
            'processed' => 0,
            'errors' => 0,
            'status' => 'ignored_unsupported',
            'stage' => $capability . '_endpoint_not_confirmed',
            'safe_message' => 'El recurso no se consultó porque su contrato remoto no está confirmado.',
        ];
    }

    /** @return array<string,mixed> */
    private function syncCandidate(int $accountId, string $candidateId): array
    {
        try {
            $row = $this->gateway()->get(
                'meli-growth',
                $accountId,
                '/seller-promotions/candidates/' . $candidateId,
                ['app_version' => 'v2'],
                'growth_candidate_event'
            );
            Database::connection()->prepare(
                'INSERT INTO ml_growth_candidates
                 (meli_account_id,external_candidate_id,external_item_id,external_promotion_id,promotion_type,status,snapshot_json,observed_at)
                 VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE external_item_id=VALUES(external_item_id),
                 external_promotion_id=VALUES(external_promotion_id),promotion_type=VALUES(promotion_type),
                 status=VALUES(status),snapshot_json=VALUES(snapshot_json),observed_at=UTC_TIMESTAMP()'
            )->execute([
                $accountId,
                $candidateId,
                $row['item_id'] ?? null,
                $row['promotion_id'] ?? null,
                $row['type'] ?? null,
                $this->scalarStatus($row['status'] ?? null),
                $this->json($row),
            ]);
            return ['processed' => 1, 'errors' => 0, 'status' => 'completed', 'stage' => 'candidate_updated'];
        } catch (MeliApiException $error) {
            return $this->eventFailure($accountId, 'promotion_candidate', $error);
        }
    }

    /** @return array<string,mixed> */
    private function syncOffer(int $accountId, string $offerId): array
    {
        try {
            $row = $this->gateway()->get(
                'meli-growth',
                $accountId,
                '/seller-promotions/offers/' . $offerId,
                ['app_version' => 'v2'],
                'growth_offer_event'
            );
            Database::connection()->prepare(
                'INSERT INTO ml_growth_offers
                 (meli_account_id,external_item_id,offer_type,status,discount_percent,snapshot_json,observed_at)
                 VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())'
            )->execute([
                $accountId,
                (string) ($row['item_id'] ?? ''),
                $row['type'] ?? null,
                $this->scalarStatus($row['status'] ?? null),
                null,
                $this->json($row),
            ]);
            return ['processed' => 1, 'errors' => 0, 'status' => 'completed', 'stage' => 'offer_updated'];
        } catch (MeliApiException $error) {
            return $this->eventFailure($accountId, 'promotion_offer', $error);
        }
    }

    /** @return array<string,mixed> */
    private function eventFailure(int $accountId, string $capability, MeliApiException $error): array
    {
        if ($error->httpStatus === 404) {
            $this->markCapability($accountId, $capability, 'expected_absence', 404, 'La notificación ya no tiene un recurso vigente.');
            return ['processed' => 0, 'errors' => 0, 'status' => 'completed', 'stage' => 'expected_absence'];
        }
        $status = $error->httpStatus === 403 ? 'permission_required' : 'temporarily_limited';
        $this->markCapability($accountId, $capability, $status, $error->httpStatus, $this->capabilityMessage($status));
        return [
            'processed' => 0,
            'errors' => 1,
            'status' => $error->httpStatus === 429 ? 'retry' : 'completed',
            'stage' => $capability,
            'delay_seconds' => $error->httpStatus === 429 ? 900 : 0,
            'safe_message' => $this->capabilityMessage($status),
        ];
    }

    /** @return array<string,mixed> */
    private function remoteFailure(int $accountId, string $stage, MeliApiException $error, int $accountOffset, int $stageIndex, int $accountCount): array
    {
        $status = match ($error->httpStatus) {
            403 => 'permission_required',
            404 => 'expected_absence',
            429 => 'temporarily_limited',
            default => 'temporarily_unavailable',
        };
        $this->markCapability($accountId, $stage, $status, $error->httpStatus, $this->capabilityMessage($status));
        if (in_array($error->httpStatus, [403, 404], true)) {
            return [
                'processed' => 0,
                'errors' => 0,
                'status' => 'pending',
                'stage' => self::STAGES[$stageIndex + 1] ?? 'completed',
                'delay_seconds' => 5,
                'safe_message' => $this->capabilityMessage($status),
                'checkpoint' => ['account_offset' => $accountOffset, 'stage_index' => $stageIndex + 1],
                'progress_current' => ($accountOffset * count(self::STAGES)) + $stageIndex + 1,
                'progress_total' => $accountCount * count(self::STAGES),
            ];
        }
        return [
            'processed' => 0,
            'errors' => 1,
            'status' => 'retry',
            'stage' => $stage,
            'delay_seconds' => $error->httpStatus === 429 ? 900 : 300,
            'safe_message' => $this->capabilityMessage($status),
            'checkpoint' => ['account_offset' => $accountOffset, 'stage_index' => $stageIndex],
            'progress_current' => ($accountOffset * count(self::STAGES)) + $stageIndex,
            'progress_total' => $accountCount * count(self::STAGES),
        ];
    }

    /** @return array<string,mixed> */
    private function continueAt(int $accountOffset, int $stageIndex, int $accountCount, string $stage, int $delay, int $processed = 0): array
    {
        return [
            'processed' => $processed,
            'errors' => 0,
            'status' => 'pending',
            'stage' => $stage,
            'delay_seconds' => $delay,
            'checkpoint' => ['account_offset' => $accountOffset, 'stage_index' => $stageIndex],
            'progress_current' => $accountOffset * count(self::STAGES),
            'progress_total' => $accountCount * count(self::STAGES),
        ];
    }

    private function gateway(): MeliReadGateway
    {
        return $this->remote ?? new MeliReadGateway();
    }

    private function markCapability(int $accountId, string $capability, string $status, ?int $httpStatus, string $message): void
    {
        Database::connection()->prepare(
            'INSERT INTO ml_growth_capabilities
             (meli_account_id,capability,status,last_http_status,safe_message,checked_at,cooldown_until)
             VALUES (?,?,?,?,?,UTC_TIMESTAMP(),IF(?=429,DATE_ADD(UTC_TIMESTAMP(),INTERVAL 15 MINUTE),NULL))
             ON DUPLICATE KEY UPDATE status=VALUES(status),last_http_status=VALUES(last_http_status),
             safe_message=VALUES(safe_message),checked_at=UTC_TIMESTAMP(),cooldown_until=VALUES(cooldown_until)'
        )->execute([$accountId, $capability, $status, $httpStatus, $message, $httpStatus]);
    }

    private function isFresh(
        string $table,
        string $keyColumn,
        int|string $key,
        string $dateColumn,
        int $hours,
        ?string $secondColumn = null,
        int|string|null $secondValue = null
    ): bool {
        $allowed = [
            'ml_growth_promotions' => ['meli_account_id', 'observed_at'],
            'ml_growth_account_visits_daily' => ['meli_account_id', 'observed_at'],
            'ml_growth_trends' => ['site_id', 'observed_at'],
            'ml_growth_highlights' => ['site_id', 'observed_at', 'category_id'],
        ];
        if (!isset($allowed[$table]) || !in_array($keyColumn, $allowed[$table], true) || !in_array($dateColumn, $allowed[$table], true)) {
            return false;
        }
        $sql = "SELECT MAX({$dateColumn}) FROM {$table} WHERE {$keyColumn}=?";
        $params = [$key];
        if ($secondColumn !== null && in_array($secondColumn, $allowed[$table], true)) {
            $sql .= " AND {$secondColumn}=?";
            $params[] = $secondValue;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $value = $stmt->fetchColumn();
        return is_string($value) && strtotime($value) >= time() - ($hours * 3600);
    }

    private function scalarStatus(mixed $status): ?string
    {
        if (is_array($status)) {
            $status = $status['id'] ?? $status['status'] ?? null;
        }
        $value = trim((string) ($status ?? ''));
        return $value !== '' ? substr($value, 0, 60) : null;
    }

    private function capabilityMessage(string $status): string
    {
        return match ($status) {
            'permission_required' => 'Mercado Libre no habilitó esta información para la cuenta.',
            'expected_absence' => 'La información es opcional y no está disponible en este momento.',
            'temporarily_limited' => 'La consulta quedó aplazada para respetar la protección de Mercado Libre.',
            default => 'La información no pudo comprobarse en este ciclo.',
        };
    }

    private function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
