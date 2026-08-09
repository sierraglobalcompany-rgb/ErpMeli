<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use JsonException;

final class MeliNotificationTopicRegistry
{
    private const CATALOG_PATH = '/resources/mercadolibre-api/generated/notification-topics.json';
    private const MAX_CATALOG_BYTES = 1048576;

    /** @var list<string> */
    private const POLICIES = [
        'sync_exact',
        'review_only',
        'capability_sync',
        'recognized_no_fetch',
        'module_event',
        'ignore',
    ];

    /**
     * The B2 webhook graph cannot operate safely if one of these exact topic
     * contracts is absent. Treating a partial catalog as usable would turn a
     * deployment error into selective loss of order logistics.
     *
     * @var array<string,array{alias:string,resource_type:string,endpoint:string,sample:string,id:string}>
     */
    private const REQUIRED_EXACT_TOPICS = [
        'order' => [
            'alias' => 'orders',
            'resource_type' => 'order',
            'endpoint' => '/orders/{id}',
            'sample' => '/orders/123',
            'id' => '123',
        ],
        'pack' => [
            'alias' => 'packs',
            'resource_type' => 'pack',
            'endpoint' => '/packs/{id}',
            'sample' => '/packs/123',
            'id' => '123',
        ],
        'shipment' => [
            'alias' => 'shipments',
            'resource_type' => 'shipment',
            'endpoint' => '/shipments/{id}',
            'sample' => '/shipments/123',
            'id' => '123',
        ],
    ];

    /** @var list<array<string,mixed>>|null */
    private ?array $rules = null;
    /** @var array<string,array{rules:list<array<string,mixed>>,failure:?string}> */
    private static array $cache = [];
    private bool $loaded = false;
    private ?string $loadFailure = null;
    private readonly string $catalogPath;

    public function __construct(?string $catalogPath = null)
    {
        $this->catalogPath = $catalogPath
            ?? AppPaths::releaseRoot() . self::CATALOG_PATH;
    }

    /**
     * @param mixed $actions
     * @return array{canonical_topic:string,resource_type:string,resource_id:string,endpoint:?string,policy:string,priority:int,actionable:bool,valid:bool,reason:string}
     */
    public function classify(string $topic, ?string $resource, mixed $actions = null): array
    {
        $topic = strtolower(trim($topic));
        $resource = trim((string) $resource);
        $rules = $this->rules();
        if ($this->loadFailure !== null) {
            return $this->invalidClassification($topic, $resource, 'topic_catalog_unavailable');
        }
        foreach ($rules as $rule) {
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

        return $this->invalidClassification($topic, $resource, 'unknown_topic');
    }

    /** @return list<array<string,mixed>> */
    public function rules(): array
    {
        if (!$this->loaded) {
            $this->load();
        }
        return $this->rules ?? [];
    }

    public function failureReason(): ?string
    {
        $this->rules();
        return $this->loadFailure;
    }

    private function load(): void
    {
        $this->loaded = true;
        if (isset(self::$cache[$this->catalogPath])) {
            $this->rules = self::$cache[$this->catalogPath]['rules'];
            $this->loadFailure = self::$cache[$this->catalogPath]['failure'];
            return;
        }
        if (!is_file($this->catalogPath) || !is_readable($this->catalogPath)) {
            $this->fail('catalog_missing');
            return;
        }
        $json = file_get_contents($this->catalogPath, false, null, 0, self::MAX_CATALOG_BYTES + 1);
        if (!is_string($json)) {
            $this->fail('catalog_unreadable');
            return;
        }
        if (strlen($json) > self::MAX_CATALOG_BYTES) {
            $this->fail('catalog_too_large');
            return;
        }
        if (trim($json) === '') {
            $this->fail('catalog_unreadable');
            return;
        }
        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->fail('catalog_malformed_json');
            return;
        }
        if (!is_array($decoded)
            || !is_string($decoded['version'] ?? null)
            || preg_match('/^\d+\.\d+\.\d+$/', (string) $decoded['version']) !== 1
            || !is_string($decoded['generated_at'] ?? null)
            || preg_match('/^\d{4}-\d{2}-\d{2}(?:T[^\s]+)?$/', (string) $decoded['generated_at']) !== 1
            || !is_array($decoded['rules'] ?? null)
            || !array_is_list($decoded['rules'])
            || $decoded['rules'] === []) {
            $this->fail('catalog_schema_invalid');
            return;
        }

        $rules = [];
        $canonicals = [];
        $aliases = [];
        foreach ($decoded['rules'] as $candidate) {
            $rule = $this->validateRule($candidate);
            if ($rule === null) {
                $this->fail('catalog_schema_invalid');
                return;
            }
            $canonical = (string) $rule['canonical'];
            if (isset($canonicals[$canonical])) {
                $this->fail('catalog_duplicate_canonical');
                return;
            }
            $canonicals[$canonical] = true;
            foreach ($rule['aliases'] as $alias) {
                if (isset($aliases[$alias])) {
                    $this->fail('catalog_duplicate_alias');
                    return;
                }
                $aliases[$alias] = true;
            }
            $rules[] = $rule;
        }
        if (!$this->requiredExactTopicsPresent($rules)) {
            $this->fail('catalog_required_exact_topic_missing');
            return;
        }
        $this->rules = $rules;
        self::$cache[$this->catalogPath] = ['rules' => $rules, 'failure' => null];
    }

    /** @return array<string,mixed>|null */
    private function validateRule(mixed $candidate): ?array
    {
        if (!is_array($candidate)
            || !is_string($candidate['canonical'] ?? null)
            || !is_string($candidate['resource_type'] ?? null)
            || !is_array($candidate['aliases'] ?? null)
            || !array_is_list($candidate['aliases'])
            || $candidate['aliases'] === []
            || !is_array($candidate['resource_patterns'] ?? null)
            || !array_is_list($candidate['resource_patterns'])
            || !is_string($candidate['policy'] ?? null)
            || !in_array($candidate['policy'], self::POLICIES, true)
            || !is_int($candidate['priority'] ?? null)
            || $candidate['priority'] < 0
            || $candidate['priority'] > 1000
            || !is_bool($candidate['actionable'] ?? null)
            || !(is_string($candidate['endpoint'] ?? null) || ($candidate['endpoint'] ?? null) === null)) {
            return null;
        }
        $canonical = strtolower(trim($candidate['canonical']));
        $resourceType = strtolower(trim($candidate['resource_type']));
        if (preg_match('/^[a-z][a-z0-9_]*$/', $canonical) !== 1
            || preg_match('/^[a-z][a-z0-9_]*$/', $resourceType) !== 1) {
            return null;
        }
        $aliases = [];
        foreach ($candidate['aliases'] as $alias) {
            if (!is_string($alias) || trim($alias) === '') {
                return null;
            }
            $normalized = strtolower(trim($alias));
            if (isset($aliases[$normalized])) {
                return null;
            }
            $aliases[$normalized] = true;
        }
        $patterns = [];
        foreach ($candidate['resource_patterns'] as $pattern) {
            if (!is_string($pattern) || trim($pattern) === '' || @preg_match($pattern, '') === false) {
                return null;
            }
            $patterns[] = $pattern;
        }
        $endpoint = $candidate['endpoint'];
        if (is_string($endpoint)
            && (preg_match('#^/(?:[A-Za-z0-9._~-]+|\{[A-Za-z][A-Za-z0-9_]*\})(?:/(?:[A-Za-z0-9._~-]+|\{[A-Za-z][A-Za-z0-9_]*\}))*$#D', $endpoint) !== 1
                || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $endpoint) === 1)) {
            return null;
        }
        if (in_array($candidate['policy'], ['sync_exact', 'review_only', 'capability_sync'], true)
            && ($patterns === [] || !is_string($endpoint))) {
            return null;
        }
        return [
            'canonical' => $canonical,
            'aliases' => array_keys($aliases),
            'resource_type' => $resourceType,
            'resource_patterns' => $patterns,
            'endpoint' => $endpoint,
            'policy' => $candidate['policy'],
            'priority' => $candidate['priority'],
            'actionable' => $candidate['actionable'],
        ];
    }

    /** @param list<array<string,mixed>> $rules */
    private function requiredExactTopicsPresent(array $rules): bool
    {
        $indexed = [];
        foreach ($rules as $rule) {
            $indexed[(string) $rule['canonical']] = $rule;
        }
        foreach (self::REQUIRED_EXACT_TOPICS as $canonical => $contract) {
            $rule = $indexed[$canonical] ?? null;
            if (!is_array($rule)
                || !in_array($contract['alias'], $rule['aliases'], true)
                || $rule['resource_type'] !== $contract['resource_type']
                || $rule['endpoint'] !== $contract['endpoint']
                || $rule['policy'] !== 'sync_exact'
                || $rule['actionable'] !== true) {
                return false;
            }
            $matched = false;
            foreach ($rule['resource_patterns'] as $pattern) {
                if (@preg_match((string) $pattern, $contract['sample'], $matches) === 1
                    && trim((string) ($matches['id'] ?? $matches[1] ?? '')) === $contract['id']) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }
        return true;
    }

    private function fail(string $reason): void
    {
        $this->rules = [];
        $this->loadFailure = $reason;
        self::$cache[$this->catalogPath] = ['rules' => [], 'failure' => $reason];
    }

    /**
     * @return array{canonical_topic:string,resource_type:string,resource_id:string,endpoint:null,policy:string,priority:int,actionable:bool,valid:bool,reason:string}
     */
    private function invalidClassification(string $topic, string $resource, string $reason): array
    {
        return [
            'canonical_topic' => 'unknown',
            'resource_type' => 'unknown',
            'resource_id' => hash('sha256', $topic . '|' . $resource),
            'endpoint' => null,
            'policy' => 'quarantine',
            'priority' => 100,
            'actionable' => false,
            'valid' => false,
            'reason' => $reason,
        ];
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
