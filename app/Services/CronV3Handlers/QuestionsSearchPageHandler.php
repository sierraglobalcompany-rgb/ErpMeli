<?php

declare(strict_types=1);

namespace App\Services\CronV3Handlers;

use App\Contracts\CronV3WorkHandler;
use App\Core\Database;
use App\Services\CronV3;
use App\Services\CronV3ExecutionContext;
use App\Services\MeliApiClient;
use App\Services\WorkEnvelope;
use App\Services\WorkResult;
use Closure;
use InvalidArgumentException;

final class QuestionsSearchPageHandler implements CronV3WorkHandler
{
    /** @var Closure(WorkEnvelope,int):array<string,mixed> */
    private readonly Closure $fetchPage;

    /** @var Closure(WorkEnvelope):mixed */
    private readonly Closure $enqueue;

    /**
     * @param null|callable(WorkEnvelope,int):array<string,mixed> $fetchPage
     * @param null|callable(WorkEnvelope):mixed $enqueue
     */
    public function __construct(?callable $fetchPage = null, ?callable $enqueue = null)
    {
        $this->fetchPage = $fetchPage !== null
            ? Closure::fromCallable($fetchPage)
            : static function (WorkEnvelope $work, int $limit): array {
                $stmt = Database::connection()->prepare(
                    'SELECT meli_user_id FROM meli_accounts WHERE id=? AND company_id=? LIMIT 1'
                );
                $stmt->execute([$work->meliAccountId, $work->companyId]);
                $sellerId = (int) $stmt->fetchColumn();
                if ($sellerId < 1) {
                    throw new InvalidArgumentException('La cuenta no pertenece a la empresa del trabajo.');
                }

                return (new MeliApiClient($work->meliAccountId))->get('/questions/search', [
                    'seller_id' => $sellerId,
                    'status' => 'UNANSWERED',
                    'limit' => $limit,
                    'api_version' => 4,
                ], ['job_type' => 'questions', 'source' => 'cron_v3_remote', 'bulk' => true]);
            };
        $this->enqueue = $enqueue !== null
            ? Closure::fromCallable($enqueue)
            : static fn (WorkEnvelope $child): array => CronV3::enqueue($child);
    }

    public function executeOne(WorkEnvelope $work, CronV3ExecutionContext $context): WorkResult
    {
        $limit = max(1, min(20, (int) ($work->payload['limit'] ?? 20)));
        $page = $context->logicalRemoteCall(fn (): array => ($this->fetchPage)($work, $limit));
        $rows = is_array($page['questions'] ?? null)
            ? $page['questions']
            : (is_array($page['results'] ?? null) ? $page['results'] : []);
        $ids = [];
        foreach ($rows as $row) {
            $id = trim((string) (is_array($row) ? ($row['id'] ?? $row['question_id'] ?? '') : $row));
            if ($id === '' || preg_match('/^[0-9]+$/', $id) !== 1) {
                throw new InvalidArgumentException('La página de preguntas contiene un identificador inválido.');
            }
            $ids[$id] = true;
        }

        foreach (array_keys($ids) as $id) {
            ($this->enqueue)(WorkEnvelope::create(
                $work->companyId,
                $work->meliAccountId,
                'question_exact',
                'remote',
                'question:' . $id,
                'question:' . $id . ':from:' . $work->inputVersion,
                ['question_id' => $id],
                $work->sourceRef ?? 'questions-unanswered',
                $work->priority
            ));
        }

        return WorkResult::completed([
            'question_count' => count($ids),
            'continuation_enabled' => false,
            'continuation_reason' => 'questions_search_has_no_confirmed_cursor',
        ]);
    }
}
