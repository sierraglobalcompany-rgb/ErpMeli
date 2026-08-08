<?php

declare(strict_types=1);

namespace App\Core\Modules;

use App\Services\MeliEndpointRegistry;

/**
 * Prevents experimental module capabilities from becoming executable work.
 */
final class ModuleJobCapabilityGate
{
    /** @param array<string,mixed> $payload */
    public function allows(string $moduleId, string $jobType, array $payload): bool
    {
        if ($moduleId !== 'meli-logistics') {
            return false;
        }

        if ($jobType === 'snapshot_sync') {
            return MeliEndpointRegistry::isConfirmed('GET', '/shipments/1');
        }

        if ($jobType !== 'event_sync') {
            return false;
        }

        $resourceType = mb_strtolower(trim((string) ($payload['resource_type'] ?? '')));
        $resourceId = $this->numericResourceId((string) ($payload['resource_id'] ?? ''));

        return in_array($resourceType, ['shipment', 'shipments'], true)
            && $resourceId !== ''
            && MeliEndpointRegistry::isConfirmed('GET', '/shipments/' . $resourceId);
    }

    private function numericResourceId(string $resource): string
    {
        if (preg_match('~(?:^|/)(\d+)(?:\?.*)?$~', trim($resource), $match) !== 1) {
            return '';
        }

        return $match[1];
    }
}
