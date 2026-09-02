<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

use App\Repositories\SettingsDefinitionRepository;

$sections = (new SettingsDefinitionRepository())->sections();
$fields = [];
foreach ($sections as $sectionKey => $section) {
    foreach ((array) ($section['fields'] ?? []) as $field) {
        $fields[(string) $field['key']] = $field + ['section' => $sectionKey];
    }
}

$expect = static function (string $key, string $label, ?string $unit = null, ?bool $advanced = null) use ($fields): void {
    k1b_assert(isset($fields[$key]), "Missing setting {$key}");
    k1b_assert((string) $fields[$key]['label'] === $label, "Unexpected label for {$key}");
    if ($unit !== null) {
        k1b_assert((string) ($fields[$key]['unit'] ?? '') === $unit, "Unexpected unit for {$key}");
    }
    if ($advanced !== null) {
        k1b_assert((bool) ($fields[$key]['advanced'] ?? false) === $advanced, "Unexpected advanced flag for {$key}");
    }
};

$expect('api.budget.global_requests_per_15m', 'Capacidad general', 'llamadas API / 15 min');
$expect('api.budget.account_requests_per_15m', 'Capacidad por cuenta', 'llamadas API / 15 min');
$expect('api.budget.web_request_api_limit', 'Máximo desde una pantalla', 'llamadas API');
$expect('sync.queue_max_chunks_per_run', 'Ventanas tomadas por cron', 'ventanas', true);
$expect('notifications.worker_batch_limit', 'Recursos por ciclo', 'recursos', true);
$expect('financial_recalc.max_orders_per_job', 'Máximo por recálculo', 'órdenes', true);

$combined = json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
foreach (['Bloques por cron', 'Recursos por lote', 'Máximo por trabajo', 'consultas / 15 min'] as $forbidden) {
    k1b_assert(!str_contains($combined, $forbidden), "Forbidden primary configuration phrase remains: {$forbidden}");
}

echo "STATUS=PASS K1C_SETTINGS_OPTIONS_CALL_CENTRIC_LABELS\n";
