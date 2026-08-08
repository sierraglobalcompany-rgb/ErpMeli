<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Core\Database;
use Throwable;

final class ModuleEventDispatcher
{
    public function __construct(private readonly ?ModuleRegistry $registry = null)
    {
    }

    public function publish(int $eventId, string $topic, ?int $accountId, ?string $resourceType, ?string $resourceId): void
    {
        $registry = $this->registry ?? new ModuleRegistry();
        foreach ($registry->enabledProviders() as $provider) {
            if (!in_array($topic, $provider->eventTopics(), true)) {
                continue;
            }
            try {
                $stmt = Database::connection()->prepare(
                    "INSERT IGNORE INTO system_module_events
                     (module_id,source_event_id,meli_account_id,topic,resource_type,remote_resource_id,status,created_at)
                     VALUES (?,?,?,?,?,?,'pending',UTC_TIMESTAMP())"
                );
                $stmt->execute([$provider->id(), $eventId, $accountId, $topic, $resourceType, $resourceId]);
                if ($stmt->rowCount() !== 1) {
                    continue;
                }
                $jobTypes = $provider->jobTypes();
                $eventJobType = in_array('event_sync', $jobTypes, true)
                    ? 'event_sync'
                    : ($jobTypes[0] ?? 'event_sync');
                (new ModuleJobRunner($registry))->enqueue(
                    $provider->id(),
                    $eventJobType,
                    $accountId,
                    ['source_event_id' => $eventId, 'topic' => $topic, 'resource_type' => $resourceType, 'resource_id' => $resourceId],
                    40
                );
            } catch (Throwable) {
                // El módulo nunca puede invalidar la recepción durable del webhook.
            }
        }
    }
}
