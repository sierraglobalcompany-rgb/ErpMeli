<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

$files = [
    'app/Views/settings/index.php',
    'app/Views/settings/manual_processing.php',
    'app/Views/settings/cron_shell.php',
    'app/Views/settings/api_health.php',
    'app/Views/settings/api_health_incidents.php',
    'app/Views/settings/api_workload.php',
    'app/Views/sync/index.php',
    'app/Views/financial_recalc/index.php',
    'app/Views/financial_recalc/errors.php',
    'app/Views/financial_recalc/show.php',
    'app/Views/notifications/automation.php',
    'app/Views/notifications/index.php',
    'app/Repositories/SettingsDefinitionRepository.php',
];

$stripTechnical = static function (string $html): string {
    $html = preg_replace('#<details class="[^"]*(?:technical|cron-advanced|cron-admin|cron-technical)[^"]*".*?</details>#su', '', $html) ?? $html;
    return preg_replace('#<code>.*?</code>#su', '', $html) ?? $html;
};

$badPhrases = [
    'api_request_logs directo',
    'Contexto útil',
    'Sin duplicar el histórico',
    'El valor 30d histórico sólo aparece una vez',
    'no lotes ni trabajos internos',
    'trabajos internos',
    'Cola disponible procesada',
    'Cola disponible',
    'Pausar cola',
    'Reanudar cola',
    'Cola única',
    'Cola financiera',
    'cola financiera',
    'cola Webhook',
    'cola Webhook‑First',
    'cola automática',
    'Cola financiera activa',
    'procesar colas',
    'Trabajos procesados',
    'Trabajos pendientes',
    'Trabajo más antiguo',
    'trabajos del cron',
    'Bloques por cron',
    'Recursos por lote',
    'Máximo por trabajo',
    'Descripciones por lote',
    'Pausa entre lotes',
    'Jobs financieros recientes',
    'No hay jobs financieros todavía',
    'Job financiero',
    'Reintentará errores de este job',
    'Cancelar job',
    'Órdenes del job',
];

foreach ($files as $file) {
    $path = __DIR__ . '/../' . $file;
    $content = file_get_contents($path);
    k1b_assert(is_string($content), "Cannot read {$file}");
    $primary = $stripTechnical($content);
    foreach ($badPhrases as $phrase) {
        k1b_assert(!str_contains($primary, $phrase), "Forbidden primary phrase '{$phrase}' remains in {$file}");
    }
}

echo "STATUS=PASS K1C_NO_BATCH_JOB_AUTHORITY_IN_PRIMARY_UI\n";
