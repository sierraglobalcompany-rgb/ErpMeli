<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

use App\Services\WebhookService;

if ($argc !== 4) {
    fwrite(STDERR, "usage: actor <context.json> <actor> <notification-id>\n");
    exit(64);
}

$context = json_decode((string) file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
if (!is_array($context)) {
    throw new RuntimeException('K2_CONCURRENT_CONTEXT_INVALID');
}
foreach (($context['env'] ?? []) as $key => $value) {
    putenv((string) $key . '=' . (string) $value);
}
if (!defined('ERP_RELEASE_ROOT')) {
    define('ERP_RELEASE_ROOT', dirname(__DIR__));
}
if (!defined('ERP_INSTALLATION_ROOT')) {
    define('ERP_INSTALLATION_ROOT', (string) $context['installation_root']);
}

$actor = preg_replace('/[^a-z0-9_-]/i', '_', (string) $argv[2]) ?: 'actor';
$barrier = rtrim((string) $context['barrier'], '/\\');
file_put_contents($barrier . '/ready-' . $actor, 'ready');
$deadline = microtime(true) + 10.0;
while (!is_file($barrier . '/go')) {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('K2_CONCURRENT_BARRIER_TIMEOUT');
    }
    usleep(10000);
}

$raw = json_encode([
    '_id' => (string) $argv[3],
    'topic' => 'questions',
    'resource' => '/questions/' . (int) $context['question_id'],
    'user_id' => (int) $context['user_id'],
    'application_id' => '123456',
    'attempts' => 1,
    'sent' => (string) $context['sent_at'],
], JSON_THROW_ON_ERROR);
$result = (new WebhookService())->receiveResult($raw, 'k2_concurrent_test', false);
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
exit(!empty($result['accepted']) ? 0 : 1);
