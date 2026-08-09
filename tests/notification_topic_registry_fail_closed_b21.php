<?php

declare(strict_types=1);

$root = dirname(__DIR__);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/app/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Services\MeliNotificationTopicRegistry;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$fixture = json_decode(
    (string) file_get_contents($root . '/resources/mercadolibre-api/generated/notification-topics.json'),
    true,
    64,
    JSON_THROW_ON_ERROR,
);
$directory = sys_get_temp_dir() . '/erp-topic-registry-' . bin2hex(random_bytes(8));
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create the topic registry fixture directory.');
}
$files = [];
$write = static function (string $name, mixed $value, bool $raw = false) use ($directory, &$files): string {
    $path = $directory . '/' . $name;
    $contents = $raw
        ? (string) $value
        : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($path, $contents, LOCK_EX) !== strlen($contents)) {
        throw new RuntimeException('Unable to write a topic registry fixture.');
    }
    $files[] = $path;
    return $path;
};
$withoutCanonical = static function (array $catalog, string $canonical): array {
    $catalog['rules'] = array_values(array_filter(
        (array) ($catalog['rules'] ?? []),
        static fn (mixed $rule): bool => !is_array($rule) || ($rule['canonical'] ?? null) !== $canonical,
    ));
    return $catalog;
};
$replaceCanonical = static function (array $catalog, string $canonical, callable $change): array {
    foreach ($catalog['rules'] as $index => $rule) {
        if (is_array($rule) && ($rule['canonical'] ?? null) === $canonical) {
            $catalog['rules'][$index] = $change($rule);
        }
    }
    return $catalog;
};
$assertUnavailable = static function (
    string $path,
    string $expectedFailure,
    string $scenario,
) use ($assert): array {
    $registry = new MeliNotificationTopicRegistry($path);
    $classification = $registry->classify('orders', '/orders/123');
    $assert($registry->rules() === [], $scenario . ' exposed partial rules.');
    $assert($registry->failureReason() === $expectedFailure, $scenario . ' reported the wrong safe failure.');
    $assert($classification['valid'] === false, $scenario . ' produced actionable work.');
    $assert($classification['policy'] === 'quarantine', $scenario . ' did not select quarantine.');
    $assert($classification['endpoint'] === null, $scenario . ' exposed a remote endpoint.');
    $assert($classification['actionable'] === false, $scenario . ' remained actionable.');
    $assert($classification['reason'] === 'topic_catalog_unavailable', $scenario . ' leaked catalog detail.');
    return $classification;
};

try {
    $validPath = $write('valid.json', $fixture);
    $valid = new MeliNotificationTopicRegistry($validPath);
    $pack = $valid->classify('packs', '/packs/123');
    $assert($valid->failureReason() === null, 'The production catalog was rejected.');
    $assert($pack['valid'] === true, 'The exact pack topic was not accepted.');
    $assert($pack['resource_type'] === 'pack' && $pack['resource_id'] === '123', 'The pack identity changed.');
    $assert($pack['endpoint'] === '/packs/{id}' && $pack['policy'] === 'sync_exact', 'The pack contract changed.');

    $classifications = [];
    $classifications[] = $assertUnavailable(
        $directory . '/missing.json',
        'catalog_missing',
        'Missing catalog',
    );
    $classifications[] = $assertUnavailable(
        $write('malformed.json', '{"rules": [', true),
        'catalog_malformed_json',
        'Malformed JSON',
    );
    $classifications[] = $assertUnavailable(
        $write('oversized.json', str_repeat(' ', 1048577), true),
        'catalog_too_large',
        'Oversized catalog',
    );

    $missingSchema = $fixture;
    unset($missingSchema['version']);
    $classifications[] = $assertUnavailable(
        $write('schema-missing.json', $missingSchema),
        'catalog_schema_invalid',
        'Missing root schema',
    );

    $badRule = $replaceCanonical(
        $fixture,
        'order',
        static function (array $rule): array {
            $rule['resource_patterns'] = ['~[unterminated~'];
            return $rule;
        },
    );
    $classifications[] = $assertUnavailable(
        $write('invalid-pattern.json', $badRule),
        'catalog_schema_invalid',
        'Invalid rule schema',
    );

    $classifications[] = $assertUnavailable(
        $write('missing-pack.json', $withoutCanonical($fixture, 'pack')),
        'catalog_required_exact_topic_missing',
        'Missing required pack',
    );

    $wrongPack = $replaceCanonical(
        $fixture,
        'pack',
        static function (array $rule): array {
            $rule['endpoint'] = '/orders/{id}';
            return $rule;
        },
    );
    $classifications[] = $assertUnavailable(
        $write('wrong-pack.json', $wrongPack),
        'catalog_required_exact_topic_missing',
        'Wrong pack contract',
    );

    $unsafeEndpoint = $replaceCanonical(
        $fixture,
        'claim',
        static function (array $rule): array {
            $rule['endpoint'] = '/../oauth/token';
            return $rule;
        },
    );
    $classifications[] = $assertUnavailable(
        $write('unsafe-endpoint.json', $unsafeEndpoint),
        'catalog_schema_invalid',
        'Unsafe endpoint path',
    );

    $duplicateAlias = $replaceCanonical(
        $fixture,
        'shipment',
        static function (array $rule): array {
            $rule['aliases'] = ['orders'];
            return $rule;
        },
    );
    $classifications[] = $assertUnavailable(
        $write('duplicate-alias.json', $duplicateAlias),
        'catalog_duplicate_alias',
        'Duplicate alias',
    );

    // Model the final transport boundary: no invalid catalog classification
    // can provide the endpoint/authority required to issue a request.
    $physicalHttpCalls = 0;
    foreach ($classifications as $classification) {
        if ($classification['valid'] === true
            && $classification['actionable'] === true
            && is_string($classification['endpoint'])) {
            $physicalHttpCalls++;
        }
    }
    $assert($physicalHttpCalls === 0, 'An invalid topic catalog crossed the HTTP boundary.');

    $source = (string) file_get_contents($root . '/app/Services/MeliNotificationTopicRegistry.php');
    $assert(!preg_match('/\b(?:curl_exec|MeliApiClient|HttpTransport)\b/', $source), 'The catalog loader owns transport.');
} finally {
    foreach ($files as $file) {
        @unlink($file);
    }
    @rmdir($directory);
}

fwrite(STDOUT, 'Notification topic registry fail-closed: ' . $checks . " checks passed\n");
