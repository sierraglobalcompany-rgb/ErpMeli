<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/CronV3CanaryControlService.php');
$assistant = (string) file_get_contents($root . '/app/Services/CronV3SetupAssistantService.php');
$migration = (string) file_get_contents($root . '/database/migrations/255_cron_v3_active_evidence_truth_2_29_11.sql');

foreach ([
    '$canaryHasEvidence',
    '($activeEnabled || $canaryHasEvidence) && $localEnabled && $remoteEnabled',
    "'canary_active_by_evidence' => \$canaryActive && !\$activeEnabled",
    'if ($canaryActive && $localEnabled && $remoteEnabled)',
    "return 'remote_canary_running';",
] as $needle) {
    if (!str_contains($service, $needle)) {
        throw new RuntimeException('CronV3CanaryControlService no reconoce canario activo por evidencia: ' . $needle);
    }
}

foreach ([
    '$canaryActiveByEvidence = $this->canaryOperationalEvidence();',
    "'canary_active_by_evidence' => \$canaryActiveByEvidence",
    'cron_v3_queue_ownership',
    "JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.mode'))='active'",
] as $needle) {
    if (!str_contains($assistant, $needle)) {
        throw new RuntimeException('CronV3SetupAssistantService no cierra Shadow por evidencia activa: ' . $needle);
    }
}

if (
    !str_contains($migration, "('app.version', '2.29.11'")
    || preg_match('/(?:INSERT|UPDATE|DELETE|ALTER)\s+(?:INTO\s+|FROM\s+|TABLE\s+)?(?:meli_orders|meli_order_items|meli_payments|meli_shipments|meli_packs|meli_oauth_states|cron_v3_work|cron_v3_queue_ownership)\b/i', $migration)
) {
    throw new RuntimeException('La migración 255 debe ser metadata segura y no tocar datos comerciales, colas ni ownership.');
}

echo "PASS cron_v3_active_evidence_truth_22911\n";
