<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/**
 * Fuente única del ritmo de una campaña interactiva.
 *
 * El bloque cuenta todas las salidas remotas de la campaña. Cambiar de
 * cuenta, cola u operación no reinicia el contador.
 */
final class ManualCampaignRhythmService
{
    /** @param array<string,mixed> $campaign */
    public function limitReason(array $campaign, ?int $now = null): ?string
    {
        $config = is_array($campaign['configuration'] ?? null)
            ? $campaign['configuration']
            : (json_decode((string) ($campaign['configuration_json'] ?? ''), true) ?: []);
        $maxBlocks = max(0, (int) ($config['max_blocks'] ?? 0));
        if ($maxBlocks > 0 && (int) ($campaign['completed_blocks'] ?? 0) >= $maxBlocks) {
            return 'max_blocks';
        }
        $maxSeconds = max(0, (int) ($config['max_duration_seconds'] ?? 0));
        if ($maxSeconds > 0 && !empty($campaign['started_at'])) {
            $started = (new SystemDatabaseUtcClock())->timestamp((string) $campaign['started_at']);
            if ($started !== null && ($now ?? time()) >= $started + $maxSeconds) {
                return 'max_duration';
            }
        }
        return null;
    }

    public function limitMessage(string $reason): string
    {
        return match ($reason) {
            'max_blocks' => 'Se completó el número máximo de bloques. Los trabajos restantes regresaron a Automatización.',
            'max_duration' => 'Se alcanzó la duración máxima. Los trabajos restantes regresaron a Automatización.',
            default => 'Se alcanzó el límite configurado. Los trabajos restantes regresaron a Automatización.',
        };
    }

    /**
     * @return array{block_calls:int,completed_blocks:int,current_block:int,block_ended:bool,delay_ms:int}
     */
    public function afterStep(
        int $previousBlockCalls,
        int $previousCompletedBlocks,
        int $outboundCalls,
        int $blockSize,
        int $intervalMs,
        int $blockPauseMs,
        bool $hasOpenWork
    ): array {
        $blockSize = max(1, $blockSize);
        $blockCalls = max(0, $previousBlockCalls) + max(0, $outboundCalls);
        $completedBlocks = max(0, $previousCompletedBlocks);
        $ended = $outboundCalls > 0 && $blockCalls >= $blockSize;
        if ($ended) {
            $completedBlocks += intdiv($blockCalls, $blockSize);
            $blockCalls %= $blockSize;
        }
        return [
            'block_calls' => $blockCalls,
            'completed_blocks' => $completedBlocks,
            'current_block' => $completedBlocks + 1,
            'block_ended' => $ended,
            'delay_ms' => $outboundCalls < 1
                ? 0
                : ($ended && $hasOpenWork ? max(0, $blockPauseMs) : max(0, $intervalMs)),
        ];
    }

    public function enforceBeforeStep(int $campaignId): ?string
    {
        $pdo = Database::connectionFresh();
        $stmt = $pdo->prepare(
            'SELECT * FROM manual_campaigns WHERE id=? FOR UPDATE'
        );
        $pdo->beginTransaction();
        try {
            $stmt->execute([$campaignId]);
            $campaign = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($campaign)) {
                $pdo->commit();
                return 'missing';
            }
            $campaign['configuration'] = json_decode((string) $campaign['configuration_json'], true) ?: [];
            $reason = $this->limitReason($campaign);
            $pdo->commit();
            if ($reason !== null) {
                (new ManualCampaignService())->finishByLimit($campaignId, $reason);
            }
            return $reason;
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
    }

    public function utcAfterMilliseconds(int $delayMs): string
    {
        $instant = DateTimeImmutable::createFromFormat(
            'U.u',
            number_format(microtime(true) + (max(0, $delayMs) / 1000), 6, '.', '')
        );
        return ($instant ?: new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s.v');
    }
}
