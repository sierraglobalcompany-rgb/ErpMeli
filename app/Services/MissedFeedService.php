<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Env;
use PDO;
use Throwable;

final class MissedFeedService
{
    public function run(int $accountId, string $topic = '', int $limit = 20): array
    {
        $settings = new AppSettingsService();
        if (!$settings->bool('notifications.missed_feeds_enabled', false)) {
            throw new \RuntimeException('Missed feeds está desactivado por seguridad.');
        }
        if (!MeliEndpointRegistry::isConfirmed('GET', '/missed_feeds')) {
            throw new \RuntimeException('Missed feeds permanece deshabilitado hasta confirmar su contrato en el mapa API local.');
        }
        $limit = max(1, min(50, $limit));
        $appId = Env::get('MELI_CLIENT_ID', '');
        if ($appId === '') {
            throw new \RuntimeException('Falta MELI_CLIENT_ID para consultar missed feeds.');
        }
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO missed_feed_runs (application_id,topic,status,limit_value,created_by) VALUES (:app,:topic,"running",:limit,:user)')
            ->execute(['app' => $appId, 'topic' => $topic ?: null, 'limit' => $limit, 'user' => Auth::id()]);
        $runId = (int) $pdo->lastInsertId();
        $inserted = 0;
        $duplicates = 0;
        $fetched = 0;
        try {
            $query = ['app_id' => $appId, 'limit' => $limit];
            if ($topic !== '') {
                $query['topic'] = $topic;
            }
            $response = (new MeliApiClient($accountId))->get('/missed_feeds', $query, ['job_type' => 'webhook_recovery', 'bulk' => true]);
            $rows = $response['results'] ?? $response['messages'] ?? $response['feeds'] ?? $response;
            $rows = is_array($rows) ? $rows : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $fetched++;
                $payload = $row['request'] ?? $row['notification'] ?? $row;
                if (!is_array($payload)) {
                    continue;
                }
                $result = (new WebhookService())->receiveResult(
                    json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
                    'missed_feeds'
                );
                if (!empty($result['accepted']) && empty($result['duplicate'])) {
                    $inserted++;
                } elseif (!empty($result['duplicate'])) {
                    $duplicates++;
                }
            }
            $pdo->prepare('UPDATE missed_feed_runs SET status="success",fetched_count=:fetched,inserted_count=:inserted,duplicate_count=:duplicates,finished_at=UTC_TIMESTAMP() WHERE id=:id')
                ->execute(['fetched' => $fetched, 'inserted' => $inserted, 'duplicates' => $duplicates, 'id' => $runId]);
            return ['fetched' => $fetched, 'inserted' => $inserted, 'duplicates' => $duplicates];
        } catch (Throwable $e) {
            $pdo->prepare('UPDATE missed_feed_runs SET status="error",error_message=:error,fetched_count=:fetched,inserted_count=:inserted,duplicate_count=:duplicates,finished_at=UTC_TIMESTAMP() WHERE id=:id')
                ->execute(['error' => mb_substr($e->getMessage(), 0, 500), 'fetched' => $fetched, 'inserted' => $inserted, 'duplicates' => $duplicates, 'id' => $runId]);
            throw $e;
        }
    }

    public function runs(): array
    {
        try {
            return Database::connection()->query('SELECT * FROM missed_feed_runs ORDER BY created_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

}
