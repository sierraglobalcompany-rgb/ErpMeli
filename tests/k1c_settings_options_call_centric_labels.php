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
$expect('automation.max_api_calls_per_cycle', 'Máximo de llamadas API por ciclo automático', 'llamadas API');
$expect('automation.api_calls_ceiling', 'Techo de llamadas API automático', 'llamadas API');
$expect('manual.api_calls_per_step', 'Máximo de llamadas API por paso manual', 'llamadas API');
$expect('manual.api_calls_ceiling', 'Techo de llamadas API manual', 'llamadas API');
$expect('api.budget.web_request_api_limit', 'Máximo desde una pantalla', 'llamadas API');
$expect('api.rhythm.billing_min_interval_seconds', 'Ritmo Billing · intervalo mínimo', 'seg', true);
$expect('financial_recalc.enabled', 'Procesamiento financiero activo');

foreach ([
    'sync.queue_max_chunks_per_run',
    'notifications.worker_batch_limit',
    'financial_recalc.max_orders_per_job',
    'financial_recalc.billing_order_ids_per_request',
] as $retiredKey) {
    k1b_assert(!isset($fields[$retiredKey]), "Retired resource/batch setting remains editable: {$retiredKey}");
}

$combined = json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
foreach ([
    'Pausar cola',
    'Cola única',
    'cola Webhook',
    'cola Webhook‑First',
    'cola automática',
    'Cola financiera activa',
    'procesar colas',
    'trabajos del cron',
    'Trabajos procesados',
    'Bloques por cron',
    'Recursos por lote',
    'Máximo por trabajo',
    'Descripciones por lote',
    'Pausa entre lotes',
    'consultas / 15 min',
] as $forbidden) {
    k1b_assert(!str_contains($combined, $forbidden), "Forbidden primary configuration phrase remains: {$forbidden}");
}

echo "STATUS=PASS K1C_SETTINGS_OPTIONS_CALL_CENTRIC_LABELS\n";
