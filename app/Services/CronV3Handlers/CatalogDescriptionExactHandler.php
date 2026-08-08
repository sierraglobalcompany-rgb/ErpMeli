<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Core\Database;
use App\Services\CronV3ExecutionContext;
use App\Services\MeliItemDescriptionService;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class CatalogDescriptionExactHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope,int,string):array<string,mixed> */
    private readonly Closure $sync;

    /** @param null|callable(WorkEnvelope,int,string):array<string,mixed> $sync */
    public function __construct(?callable $sync = null)
    {
        $this->sync = $sync !== null
            ? Closure::fromCallable($sync)
            : static function (WorkEnvelope $work, int $localId, string $externalId): array {
                $stmt = Database::connection()->prepare(
                    'SELECT i.id FROM meli_items i
                     JOIN meli_accounts a ON a.id=i.meli_account_id AND a.company_id=?
                     WHERE i.id=? AND i.meli_account_id=? AND i.external_item_id=? LIMIT 1'
                );
                $stmt->execute([$work->companyId, $localId, $work->meliAccountId, $externalId]);
                if ((int) $stmt->fetchColumn() !== $localId) {
                    throw new InvalidArgumentException('La publicación no pertenece al alcance del trabajo.');
                }
                $service = new MeliItemDescriptionService($work->meliAccountId);
                $snapshot = $service->fetchRemoteSnapshot($externalId);
                $service->persistSnapshot($localId, $work->meliAccountId, $externalId, $snapshot);
                return $snapshot;
            };
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $localId = (int) ($work->payload['meli_item_id'] ?? 0);
        $externalId = strtoupper(trim((string) ($work->payload['external_item_id'] ?? '')));
        if ($localId < 1 || preg_match('/^[A-Z]{2,4}[0-9]+$/', $externalId) !== 1) {
            throw new InvalidArgumentException('catalog_description_exact requiere meli_item_id y external_item_id.');
        }
        $snapshot = $context->logicalRemoteCall(
            fn (): array => ($this->sync)($work, $localId, $externalId)
        );
        $status = (string) ($snapshot['source_status'] ?? 'error');
        if ($status === 'error') {
            return WorkResult::review('description_remote_error', [
                'meli_item_id' => $localId,
                'external_item_id' => $externalId,
            ]);
        }

        return WorkResult::completed([
            'meli_item_id' => $localId,
            'external_item_id' => $externalId,
            'source_status' => $status,
        ]);
    }
}
