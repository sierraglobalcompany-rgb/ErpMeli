<?php

declare(strict_types=1);

namespace App\Modules\MeliInsights\Services;

use App\Core\Database;
use App\Modules\Shared\Gateways\CoreReadGateway;
use App\Modules\Shared\Services\AbstractModuleSyncService;
use App\Modules\Shared\Services\MeliReadGateway;
use App\Services\AppSettingsService;
use App\Services\MeliApiException;
use App\Services\MeliEndpointRegistry;
use PDO;
use Throwable;

final class InsightsSyncService extends AbstractModuleSyncService
{
    private const STAGES = ['capabilities', 'reputation', 'prices', 'performance', 'moderations', 'competition'];

    protected function syncAccount(int $accountId, array $account, array $job): array
    {
        $checkpoint = is_array($job['checkpoint'] ?? null) ? $job['checkpoint'] : [];
        $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];
        $targetId = trim((string) ($payload['resource_id'] ?? ''));
        $topic = (string) ($payload['topic'] ?? '');
        $priceEventOnly = $targetId !== '' && $topic === 'items_prices';
        $stage = (string) ($checkpoint['stage'] ?? ($priceEventOnly ? 'prices' : 'capabilities'));
        if (!in_array($stage, self::STAGES, true)) {
            $stage = 'capabilities';
        }
        $offset = max(0, (int) ($checkpoint['offset'] ?? 0));
        $gateway = new CoreReadGateway();
        $totalItems = $targetId !== '' ? 1 : $gateway->countItems($accountId);
        $batchSize = max(1, min(20, (new AppSettingsService())->int('module.insights.batch_size', 5)));
        $items = $targetId !== ''
            ? array_values(array_filter([$gateway->findItem($accountId, $targetId)]))
            : $gateway->items($accountId, $batchSize, $offset);

        if ($stage === 'capabilities') {
            $this->seedCapabilities($accountId);
            return $this->nextStage('reputation', 0, $totalItems, 0, 0);
        }
        if ($stage === 'reputation') {
            $reputationPath = '/users/' . max(1, (int) ($account['meli_user_id'] ?? 0));
            if (!MeliEndpointRegistry::isConfirmed('GET', $reputationPath)) {
                $this->markCapability(
                    $accountId,
                    'reputation',
                    'investigating',
                    null,
                    'Operación deshabilitada hasta confirmar su contrato en el mapa API local.'
                );
                return $this->nextStage('prices', 0, $totalItems, 0, 0);
            }
            $result = $this->syncReputation($accountId, (int) ($account['meli_user_id'] ?? 0));
            if ($result['retry']) {
                return $this->retryResult($stage, $offset, $totalItems, $result['message']);
            }
            return $this->nextStage('prices', 0, $totalItems, $result['processed'], $result['errors']);
        }

        if (!$this->stageEndpointConfirmed($stage)) {
            $capability = match ($stage) {
                'prices' => 'sale_price',
                'performance' => 'performance',
                'moderations' => 'moderation',
                'competition' => 'competition',
                default => $stage,
            };
            $this->markCapability(
                $accountId,
                $capability,
                'investigating',
                null,
                'Operación deshabilitada hasta confirmar su contrato en el mapa API local.'
            );
            $result = ['processed' => 0, 'errors' => 0, 'retry' => false, 'message' => null];
        } else {
            $result = match ($stage) {
            'prices' => $this->syncPrices($accountId, $items, $targetId !== ''),
            'performance' => $this->syncPerformance($accountId, $items, $targetId !== ''),
            'moderations' => $this->syncModerations($accountId, $items, $targetId !== ''),
            'competition' => $this->syncCompetition($accountId, $items, $targetId !== ''),
            default => ['processed' => 0, 'errors' => 0, 'retry' => false, 'message' => null],
            };
        }
        if ($result['retry']) {
            return $this->retryResult($stage, $offset, $totalItems, $result['message']);
        }
        if ($priceEventOnly && $stage === 'prices') {
            return [
                'status' => 'completed',
                'stage' => 'completed',
                'processed' => $result['processed'],
                'errors' => $result['errors'],
                'progress_current' => 1,
                'progress_total' => 1,
            ];
        }

        $nextOffset = $offset + count($items);
        if ($targetId === '' && $items !== [] && $nextOffset < $totalItems) {
            return [
                'status' => 'pending',
                'stage' => $stage,
                'processed' => $result['processed'],
                'errors' => $result['errors'],
                'delay_seconds' => 10,
                'progress_current' => $this->progress($stage, $nextOffset, $totalItems),
                'progress_total' => max(1, $totalItems * count(self::STAGES)),
                'checkpoint' => ['stage' => $stage, 'offset' => $nextOffset],
            ];
        }

        $nextStage = $this->stageAfter($stage);
        if ($nextStage === null || ($targetId !== '' && $stage === 'competition')) {
            return [
                'status' => 'completed',
                'stage' => 'completed',
                'processed' => $result['processed'],
                'errors' => $result['errors'],
                'progress_current' => max(1, $totalItems * count(self::STAGES)),
                'progress_total' => max(1, $totalItems * count(self::STAGES)),
            ];
        }
        return $this->nextStage($nextStage, 0, $totalItems, $result['processed'], $result['errors']);
    }

    /** @param list<array<string,mixed>> $items @return array{processed:int,errors:int,retry:bool,message:?string} */
    private function syncPrices(int $accountId, array $items, bool $force): array
    {
        return $this->forItems($accountId, 'sale_price', $items, $force, function (array $item, array $payload) use ($accountId): void {
            $externalId = (string) $item['external_item_id'];
            $standard = $payload['regular_amount'] ?? $payload['standard_amount'] ?? $payload['amount'] ?? $item['price'] ?? null;
            $effective = $payload['amount'] ?? $payload['effective_amount'] ?? $item['price'] ?? null;
            $currency = $payload['currency_id'] ?? null;
            $context = is_array($payload['metadata'] ?? null)
                ? (string) ($payload['metadata']['context'] ?? 'marketplace')
                : 'marketplace';
            $hash = hash('sha256', implode('|', [$standard, $effective, $currency, $context]));
            $pdo = Database::connection();
            $current = $pdo->prepare(
                'SELECT snapshot_hash FROM ml_insights_item_prices
                 WHERE meli_account_id=? AND external_item_id=? AND context=? LIMIT 1'
            );
            $current->execute([$accountId, $externalId, $context]);
            $changed = (string) ($current->fetchColumn() ?: '') !== $hash;
            $pdo->prepare(
                "INSERT INTO ml_insights_item_prices
                 (meli_account_id,external_item_id,standard_amount,effective_amount,currency_id,context,valid_from,valid_to,
                  status,snapshot_json,snapshot_hash,observed_at)
                 VALUES (?,?,?,?,?,?,?,?, 'confirmed',?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE standard_amount=VALUES(standard_amount),effective_amount=VALUES(effective_amount),
                  currency_id=VALUES(currency_id),valid_from=VALUES(valid_from),valid_to=VALUES(valid_to),
                  status='confirmed',snapshot_json=VALUES(snapshot_json),snapshot_hash=VALUES(snapshot_hash),observed_at=UTC_TIMESTAMP()"
            )->execute([
                $accountId,
                $externalId,
                $standard,
                $effective,
                $currency,
                $context,
                $payload['start_time'] ?? $payload['valid_from'] ?? null,
                $payload['end_time'] ?? $payload['valid_to'] ?? null,
                $this->json($payload),
                $hash,
            ]);
            if ($changed && $effective !== null) {
                $pdo->prepare(
                    'INSERT IGNORE INTO ml_insights_price_history
                     (meli_account_id,external_item_id,amount,currency_id,price_type,snapshot_hash,observed_at)
                     VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())'
                )->execute([$accountId, $externalId, $effective, $currency, 'effective', $hash]);
            }
        });
    }

    private function stageEndpointConfirmed(string $stage): bool
    {
        $sample = 'MCO1';
        $path = match ($stage) {
            'prices' => '/items/' . $sample . '/sale_price',
            'performance' => '/item/' . $sample . '/performance',
            'moderations' => '/moderations/last_moderation/' . $sample . '-ITM',
            'competition' => '/items/' . $sample . '/price_to_win',
            default => null,
        };
        return $path === null || MeliEndpointRegistry::isConfirmed('GET', $path);
    }

    /** @param list<array<string,mixed>> $items @return array{processed:int,errors:int,retry:bool,message:?string} */
    private function syncPerformance(int $accountId, array $items, bool $force): array
    {
        return $this->forItems($accountId, 'performance', $items, $force, function (array $item, array $payload) use ($accountId): void {
            $level = (string) ($payload['level'] ?? $payload['status'] ?? 'unknown');
            Database::connection()->prepare(
                "INSERT INTO ml_insights_item_performance
                 (meli_account_id,external_item_id,status,level,score,completed_json,pending_json,recommendations_json,snapshot_json,observed_at)
                 VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE status=VALUES(status),level=VALUES(level),score=VALUES(score),
                  completed_json=VALUES(completed_json),pending_json=VALUES(pending_json),
                  recommendations_json=VALUES(recommendations_json),snapshot_json=VALUES(snapshot_json),observed_at=UTC_TIMESTAMP()"
            )->execute([
                $accountId,
                (string) $item['external_item_id'],
                'confirmed',
                $level,
                $payload['score'] ?? null,
                $this->json($payload['completed'] ?? $payload['achievements'] ?? []),
                $this->json($payload['pending'] ?? $payload['goals'] ?? []),
                $this->json($payload['recommendations'] ?? $payload['actions'] ?? []),
                $this->json($payload),
            ]);
        });
    }

    /** @param list<array<string,mixed>> $items @return array{processed:int,errors:int,retry:bool,message:?string} */
    private function syncModerations(int $accountId, array $items, bool $force): array
    {
        return $this->forItems($accountId, 'moderation', $items, $force, function (array $item, array $payload) use ($accountId): void {
            $externalId = (string) $item['external_item_id'];
            $moderationId = (string) ($payload['id'] ?? $payload['moderation_id'] ?? ('latest-' . $externalId));
            Database::connection()->prepare(
                "INSERT INTO ml_insights_moderations
                 (meli_account_id,external_item_id,moderation_id,status,reason,affected_fields_json,snapshot_json,observed_at)
                 VALUES (?,?,?,?,?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE status=VALUES(status),reason=VALUES(reason),
                  affected_fields_json=VALUES(affected_fields_json),snapshot_json=VALUES(snapshot_json),observed_at=UTC_TIMESTAMP()"
            )->execute([
                $accountId,
                $externalId,
                $moderationId,
                $payload['status'] ?? $payload['current_status'] ?? 'unknown',
                $payload['reason'] ?? $payload['cause'] ?? $payload['message'] ?? null,
                $this->json($payload['affected_fields'] ?? $payload['fields'] ?? []),
                $this->json($payload),
            ]);
        });
    }

    /** @param list<array<string,mixed>> $items @return array{processed:int,errors:int,retry:bool,message:?string} */
    private function syncCompetition(int $accountId, array $items, bool $force): array
    {
        return $this->forItems($accountId, 'competition', $items, $force, function (array $item, array $payload) use ($accountId): void {
            Database::connection()->prepare(
                "INSERT INTO ml_insights_catalog_competition
                 (meli_account_id,external_item_id,status,eligibility,current_price,suggested_price,currency_id,reason,snapshot_json,observed_at)
                 VALUES (?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE status=VALUES(status),eligibility=VALUES(eligibility),
                  current_price=VALUES(current_price),suggested_price=VALUES(suggested_price),currency_id=VALUES(currency_id),
                  reason=VALUES(reason),snapshot_json=VALUES(snapshot_json),observed_at=UTC_TIMESTAMP()"
            )->execute([
                $accountId,
                (string) $item['external_item_id'],
                $payload['status'] ?? $payload['winner'] ?? 'unknown',
                $payload['eligible'] ?? $payload['eligibility'] ?? null,
                $payload['current_price'] ?? $item['price'] ?? null,
                $payload['price_to_win'] ?? $payload['suggested_price'] ?? null,
                $payload['currency_id'] ?? null,
                $payload['reason'] ?? $payload['reason_code'] ?? null,
                $this->json($payload),
            ]);
        });
    }

    /** @return array{processed:int,errors:int,retry:bool,message:?string} */
    private function syncReputation(int $accountId, int $meliUserId): array
    {
        if ($meliUserId < 1) {
            $this->markCapability($accountId, 'reputation', 'unsupported', null, 'La cuenta no tiene usuario Mercado Libre identificado.');
            return ['processed' => 0, 'errors' => 1, 'retry' => false, 'message' => null];
        }
        $fresh = Database::connection()->prepare(
            'SELECT 1 FROM ml_insights_reputation_snapshots WHERE meli_account_id=? AND observed_on=UTC_DATE() LIMIT 1'
        );
        $fresh->execute([$accountId]);
        if ($fresh->fetchColumn()) {
            return ['processed' => 0, 'errors' => 0, 'retry' => false, 'message' => null];
        }
        try {
            $payload = (new MeliReadGateway())->get('meli-insights', $accountId, '/users/' . $meliUserId, [], 'module_insights_reputation');
            $reputation = is_array($payload['seller_reputation'] ?? null) ? $payload['seller_reputation'] : [];
            Database::connection()->prepare(
                "INSERT INTO ml_insights_reputation_snapshots
                 (meli_account_id,level_id,power_seller_status,transactions_json,metrics_json,observed_on,snapshot_json)
                 VALUES (?,?,?,?,?,UTC_DATE(),?)
                 ON DUPLICATE KEY UPDATE level_id=VALUES(level_id),power_seller_status=VALUES(power_seller_status),
                  transactions_json=VALUES(transactions_json),metrics_json=VALUES(metrics_json),snapshot_json=VALUES(snapshot_json)"
            )->execute([
                $accountId,
                $reputation['level_id'] ?? null,
                $payload['power_seller_status'] ?? $reputation['power_seller_status'] ?? null,
                $this->json($reputation['transactions'] ?? []),
                $this->json($reputation['metrics'] ?? []),
                $this->json($payload),
            ]);
            $this->markCapability($accountId, 'reputation', 'supported', 200, null);
            return ['processed' => 1, 'errors' => 0, 'retry' => false, 'message' => null];
        } catch (MeliApiException $error) {
            $status = $this->capabilityStatus($error->httpStatus);
            $this->markCapability($accountId, 'reputation', $status, $error->httpStatus, $this->safeCapabilityMessage($status));
            return ['processed' => 0, 'errors' => 1, 'retry' => $error->httpStatus === 429, 'message' => $this->safeCapabilityMessage($status)];
        }
    }

    /**
     * @param list<array<string,mixed>> $items
     * @param callable(array<string,mixed>,array<string,mixed>):void $store
     * @return array{processed:int,errors:int,retry:bool,message:?string}
     */
    private function forItems(int $accountId, string $capability, array $items, bool $force, callable $store): array
    {
        $processed = 0;
        $errors = 0;
        foreach ($items as $item) {
            $externalId = (string) ($item['external_item_id'] ?? '');
            if ($externalId === '') {
                continue;
            }
            if (!$force && $this->isFresh($accountId, $externalId, $capability)) {
                continue;
            }
            $path = match ($capability) {
                'sale_price' => '/items/' . rawurlencode($externalId) . '/sale_price',
                'performance' => '/item/' . rawurlencode($externalId) . '/performance',
                'moderation' => '/moderations/last_moderation/' . rawurlencode($externalId) . '-ITM',
                'competition' => '/items/' . rawurlencode($externalId) . '/price_to_win',
                default => throw new \LogicException('Capacidad Insights desconocida.'),
            };
            try {
                $payload = (new MeliReadGateway())->get('meli-insights', $accountId, $path, [], 'module_insights_' . $capability);
                $store($item, $payload);
                $this->markItemCapability($accountId, $externalId, $capability, 'available', 200, 'Información disponible para esta publicación.');
                $this->markCapability($accountId, $capability, 'supported', 200, null);
                $processed++;
            } catch (MeliApiException $error) {
                if ($capability === 'competition' && $error->httpStatus === 404) {
                    $this->storeNotEligibleCompetition($accountId, $externalId, $item);
                    $this->markItemCapability($accountId, $externalId, $capability, 'item_not_eligible', 404, 'La publicación no participa en catálogo competitivo.');
                    $this->markCapability($accountId, $capability, 'supported', 200, 'La operación está disponible; algunas publicaciones pueden no ser elegibles.');
                    $processed++;
                    continue;
                }
                if (in_array($error->httpStatus, [400, 404], true)) {
                    $itemStatus = $error->httpStatus === 404 ? 'expected_absence' : 'item_not_eligible';
                    $this->markItemCapability(
                        $accountId,
                        $externalId,
                        $capability,
                        $itemStatus,
                        $error->httpStatus,
                        $itemStatus === 'expected_absence'
                            ? 'Mercado Libre no informó datos para esta publicación.'
                            : 'Esta publicación no es elegible para la operación.'
                    );
                    $this->markCapability($accountId, $capability, 'supported', 200, 'La operación está disponible; el resultado depende de cada publicación.');
                    $processed++;
                    continue;
                }
                $status = $this->capabilityStatus($error->httpStatus);
                $this->markItemCapability($accountId, $externalId, $capability, $status, $error->httpStatus, $this->safeCapabilityMessage($status));
                $this->markCapability($accountId, $capability, $status, $error->httpStatus, $this->safeCapabilityMessage($status));
                $errors++;
                if ($error->httpStatus === 429) {
                    return ['processed' => $processed, 'errors' => $errors, 'retry' => true, 'message' => 'Mercado Libre pidió reducir consultas. El trabajo continuará después de la pausa segura.'];
                }
            } catch (Throwable) {
                $this->markItemCapability($accountId, $externalId, $capability, 'temporarily_unavailable', null, 'No fue posible completar la operación en este ciclo.');
                $this->markCapability($accountId, $capability, 'temporarily_unavailable', null, 'No fue posible completar la operación en este ciclo.');
                $errors++;
            }
        }
        return ['processed' => $processed, 'errors' => $errors, 'retry' => false, 'message' => null];
    }

    private function isFresh(int $accountId, string $externalId, string $capability): bool
    {
        [$table, $minutes] = match ($capability) {
            'sale_price' => ['ml_insights_item_prices', (new AppSettingsService())->int('module.insights.price_ttl_minutes', 360)],
            'performance' => ['ml_insights_item_performance', (new AppSettingsService())->int('module.insights.performance_ttl_minutes', 1440)],
            'moderation' => ['ml_insights_moderations', 1440],
            'competition' => ['ml_insights_catalog_competition', (new AppSettingsService())->int('module.insights.competition_ttl_minutes', 360)],
            default => ['', 0],
        };
        if ($table === '' || $minutes < 1) {
            return false;
        }
        $stmt = Database::connection()->prepare(
            "SELECT 1 FROM {$table}
             WHERE meli_account_id=? AND external_item_id=?
               AND observed_at>=DATE_SUB(UTC_TIMESTAMP(),INTERVAL ? MINUTE)
             LIMIT 1"
        );
        $stmt->execute([$accountId, $externalId, $minutes]);
        return (bool) $stmt->fetchColumn();
    }

    private function seedCapabilities(int $accountId): void
    {
        foreach (['sale_price', 'performance', 'moderation', 'competition', 'reputation'] as $capability) {
            Database::connection()->prepare(
                "INSERT INTO ml_insights_account_capabilities
                 (meli_account_id,capability,status,safe_message,last_checked_at)
                 VALUES (?,?,'pending','Pendiente de comprobar con una publicación de esta cuenta.',UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE status=IF(status IN ('supported','permission_required','unsupported'),status,'pending'),
                  last_checked_at=IF(status IN ('supported','permission_required','unsupported'),last_checked_at,UTC_TIMESTAMP())"
            )->execute([$accountId, $capability]);
        }
    }

    private function markCapability(int $accountId, string $capability, string $status, ?int $httpStatus, ?string $message): void
    {
        $cooldownMinutes = $status === 'temporarily_unavailable' ? 15 : 0;
        Database::connection()->prepare(
            "INSERT INTO ml_insights_account_capabilities
             (meli_account_id,capability,status,last_http_status,safe_message,cooldown_until,last_checked_at)
             VALUES (?,?,?,?,?,IF(? > 0,DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? MINUTE),NULL),UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE status=VALUES(status),last_http_status=VALUES(last_http_status),
              safe_message=VALUES(safe_message),cooldown_until=VALUES(cooldown_until),last_checked_at=UTC_TIMESTAMP()"
        )->execute([$accountId, $capability, $status, $httpStatus, $message, $cooldownMinutes, $cooldownMinutes]);
    }

    private function markItemCapability(
        int $accountId,
        string $externalId,
        string $capability,
        string $status,
        ?int $httpStatus,
        ?string $message
    ): void {
        Database::connection()->prepare(
            "INSERT INTO ml_insights_item_capabilities
             (meli_account_id,external_item_id,capability,status,last_http_status,safe_message,observed_at)
             VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE status=VALUES(status),last_http_status=VALUES(last_http_status),
              safe_message=VALUES(safe_message),observed_at=UTC_TIMESTAMP()"
        )->execute([$accountId, $externalId, $capability, $status, $httpStatus, $message]);
    }

    private function storeNotEligibleCompetition(int $accountId, string $externalId, array $item): void
    {
        Database::connection()->prepare(
            "INSERT INTO ml_insights_catalog_competition
             (meli_account_id,external_item_id,status,eligibility,current_price,reason,snapshot_json,observed_at)
             VALUES (?,?,'not_eligible','not_eligible',?,'La publicación no participa en price_to_win','{}',UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE status='not_eligible',eligibility='not_eligible',
              current_price=VALUES(current_price),reason=VALUES(reason),observed_at=UTC_TIMESTAMP()"
        )->execute([$accountId, $externalId, $item['price'] ?? null]);
    }

    private function capabilityStatus(?int $httpStatus): string
    {
        return match ($httpStatus) {
            403 => 'permission_required',
            429 => 'temporarily_unavailable',
            default => 'temporarily_unavailable',
        };
    }

    private function safeCapabilityMessage(string $status): string
    {
        return match ($status) {
            'permission_required' => 'La cuenta no tiene permiso para esta operación.',
            'unsupported' => 'Esta operación no está disponible para la publicación o la cuenta.',
            default => 'La operación está temporalmente limitada; el ERP reintentará de forma segura.',
        };
    }

    /** @return array<string,mixed> */
    private function nextStage(string $stage, int $offset, int $totalItems, int $processed, int $errors): array
    {
        return [
            'status' => 'pending',
            'stage' => $stage,
            'processed' => $processed,
            'errors' => $errors,
            'delay_seconds' => 5,
            'progress_current' => $this->progress($stage, $offset, $totalItems),
            'progress_total' => max(1, $totalItems * count(self::STAGES)),
            'checkpoint' => ['stage' => $stage, 'offset' => $offset],
        ];
    }

    /** @return array<string,mixed> */
    private function retryResult(string $stage, int $offset, int $totalItems, ?string $message): array
    {
        return [
            'status' => 'retry',
            'stage' => $stage,
            'processed' => 0,
            'errors' => 1,
            'delay_seconds' => 900,
            'progress_current' => $this->progress($stage, $offset, $totalItems),
            'progress_total' => max(1, $totalItems * count(self::STAGES)),
            'checkpoint' => ['stage' => $stage, 'offset' => $offset],
            'safe_message' => $message,
        ];
    }

    private function stageAfter(string $stage): ?string
    {
        $index = array_search($stage, self::STAGES, true);
        return is_int($index) && isset(self::STAGES[$index + 1]) ? self::STAGES[$index + 1] : null;
    }

    private function progress(string $stage, int $offset, int $totalItems): int
    {
        $index = array_search($stage, self::STAGES, true);
        return max(0, (is_int($index) ? $index : 0) * max(1, $totalItems) + $offset);
    }

    private function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
