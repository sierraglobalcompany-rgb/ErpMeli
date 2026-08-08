<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use Closure;
use Throwable;

/**
 * Materializa trabajo V3 desde productores de negocio sin ejecutar handlers.
 */
final class CronV3ProducerService
{
    /** @var Closure(string,string):bool */
    private readonly Closure $owns;

    /** @var Closure(WorkEnvelope):array{id:int,created:bool,status:string} */
    private readonly Closure $enqueue;

    /**
     * @param null|callable(string,string):bool $owns
     * @param null|callable(WorkEnvelope):array{id:int,created:bool,status:string} $enqueue
     */
    public function __construct(?callable $owns = null, ?callable $enqueue = null)
    {
        $this->owns = $owns !== null
            ? Closure::fromCallable($owns)
            : static fn (string $workType, string $lane): bool => self::ownsInDatabase($workType, $lane);
        $this->enqueue = $enqueue !== null
            ? Closure::fromCallable($enqueue)
            : static fn (WorkEnvelope $work): array => CronV3::enqueue($work);
    }

    public function financialLocalProjection(
        int $companyId,
        int $accountId,
        int $orderId,
        string $saleKey,
        string $inputVersion,
        int $priority = 20,
    ): bool {
        if ($orderId < 1 || $saleKey === '' || !$this->validInputVersion($inputVersion)) {
            return false;
        }

        return $this->enqueueIfOwned(WorkEnvelope::create(
            $companyId,
            $accountId,
            'financial_local_projection',
            'local',
            'financial-local-order:' . $orderId,
            $inputVersion,
            ['meli_order_id' => $orderId, 'sale_key' => $saleKey],
            'meli_order:' . $orderId,
            $priority,
        ));
    }

    public function saleBillingCapture(
        int $companyId,
        int $accountId,
        int $jobId,
        string $saleKey,
        string $inputVersion,
        int $priority = 20,
    ): bool {
        if ($jobId < 1 || $saleKey === '' || !$this->validInputVersion($inputVersion)) {
            return false;
        }

        return $this->enqueueIfOwned(WorkEnvelope::create(
            $companyId,
            $accountId,
            'sale_billing_capture',
            'remote',
            'sale-billing:' . $saleKey,
            $inputVersion,
            ['job_id' => $jobId, 'sale_key' => $saleKey],
            'sale_financial_reconciliation:' . $jobId,
            $priority,
        ));
    }

    public function claimsSearchPage(
        int $companyId,
        int $accountId,
        string $requestVersion,
        int $priority = 20,
    ): bool {
        if ($requestVersion === '') {
            return false;
        }

        return $this->enqueueIfOwned(WorkEnvelope::create(
            $companyId,
            $accountId,
            'claims_search_page',
            'remote',
            'claims-opened-page:0',
            $requestVersion,
            ['limit' => 20, 'offset' => 0],
            'claims-opened',
            $priority,
        ));
    }

    private function enqueueIfOwned(WorkEnvelope $work): bool
    {
        try {
            if (!(($this->owns)($work->workType, $work->lane))) {
                return false;
            }
            $result = ($this->enqueue)($work);
            return $result['id'] > 0;
        } catch (Throwable) {
            // El productor nunca debe romper la persistencia de negocio. El
            // adaptador legacy o el siguiente intento materializarán la unidad.
            return false;
        }
    }

    private function validInputVersion(string $inputVersion): bool
    {
        return preg_match('/^[a-f0-9]{64}$/', $inputVersion) === 1;
    }

    private static function ownsInDatabase(string $workType, string $lane): bool
    {
        try {
            $schema = new SchemaInspectorService();
            if (!$schema->hasTable('cron_v3_queue_ownership')
                || !$schema->hasTable('cron_v3_work')) {
                return false;
            }
            $statement = Database::connectionFresh()->prepare(
                'SELECT 1 FROM cron_v3_queue_ownership
                 WHERE queue_key=? AND lane=? AND owner_engine="v3" AND enabled=1 LIMIT 1'
            );
            $statement->execute([$workType, $lane]);
            return $statement->fetchColumn() !== false;
        } catch (Throwable) {
            // Sin autoridad V3 legible, el productor deja disponible el fallback V2.
            return false;
        }
    }
}
