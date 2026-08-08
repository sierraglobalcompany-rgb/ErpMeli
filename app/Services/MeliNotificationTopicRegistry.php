<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;

final class MeliNotificationTopicRegistry
{
    /** @var list<array<string,mixed>>|null */
    private static ?array $rules = null;

    /**
     * @param mixed $actions
     * @return array{canonical_topic:string,resource_type:string,resource_id:string,endpoint:?string,policy:string,priority:int,actionable:bool,valid:bool,reason:string}
     */
    public function classify(string $topic, ?string $resource, mixed $actions = null): array
    {
        $topic = strtolower(trim($topic));
        $resource = trim((string) $resource);
        foreach ($this->rules() as $rule) {
            $aliases = array_map('strtolower', is_array($rule['aliases'] ?? null) ? $rule['aliases'] : []);
            if (!in_array($topic, $aliases, true)) {
                continue;
            }
            $id = '';
            foreach (is_array($rule['resource_patterns'] ?? null) ? $rule['resource_patterns'] : [] as $pattern) {
                if (@preg_match((string) $pattern, $resource, $matches) === 1) {
                    $id = trim((string) ($matches['id'] ?? $matches[1] ?? ''));
                    break;
                }
            }
            $policy = (string) ($rule['policy'] ?? 'ignore');
            $resourceType = (string) ($rule['resource_type'] ?? 'informational');
            if ($id === '' && in_array($policy, ['sync_exact', 'review_only', 'capability_sync'], true)) {
                $id = $this->idFromActions($actions, $resourceType);
            }
            $valid = $id !== '' || in_array($policy, ['ignore', 'recognized_no_fetch'], true);
            return [
                'canonical_topic' => (string) ($rule['canonical'] ?? 'informational'),
                'resource_type' => $resourceType,
                'resource_id' => $id !== '' ? $id : hash('sha256', $topic . '|' . $resource),
                'endpoint' => isset($rule['endpoint']) && is_string($rule['endpoint']) ? $rule['endpoint'] : null,
                'policy' => $policy,
                'priority' => (int) ($rule['priority'] ?? 100),
                'actionable' => !empty($rule['actionable']),
                'valid' => $valid,
                'reason' => $valid ? 'recognized' : 'invalid_resource',
            ];
        }

        return [
            'canonical_topic' => 'unknown',
            'resource_type' => 'unknown',
            'resource_id' => hash('sha256', $topic . '|' . $resource),
            'endpoint' => null,
            'policy' => 'quarantine',
            'priority' => 100,
            'actionable' => false,
            'valid' => false,
            'reason' => 'unknown_topic',
        ];
    }

    /** @return list<array<string,mixed>> */
    public function rules(): array
    {
        if (self::$rules !== null) {
            return self::$rules;
        }
        $file = AppPaths::releaseRoot() . '/resources/mercadolibre-api/generated/notification-topics.json';
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        self::$rules = is_array($decoded['rules'] ?? null) ? array_values($decoded['rules']) : [];
        return self::$rules;
    }

    private function idFromActions(mixed $actions, string $resourceType): string
    {
        if (!is_array($actions)) {
            return '';
        }
        foreach ($actions as $action) {
            if (!is_array($action)) {
                continue;
            }
            $candidate = $action['resource'] ?? $action['resource_id'] ?? $action['id'] ?? null;
            if (is_scalar($candidate)) {
                $candidate = trim((string) $candidate);
                if ($candidate !== '' && preg_match('/^[A-Za-z0-9_-]+$/', $candidate) === 1) {
                    return $candidate;
                }
            }
            if (isset($action['resource']) && is_string($action['resource'])) {
                foreach ($this->rules() as $rule) {
                    if ((string) ($rule['resource_type'] ?? '') !== $resourceType) {
                        continue;
                    }
                    foreach (is_array($rule['resource_patterns'] ?? null) ? $rule['resource_patterns'] : [] as $pattern) {
                        if (@preg_match((string) $pattern, $action['resource'], $matches) === 1) {
                            return trim((string) ($matches['id'] ?? $matches[1] ?? ''));
                        }
                    }
                }
            }
        }
        return '';
    }
}
