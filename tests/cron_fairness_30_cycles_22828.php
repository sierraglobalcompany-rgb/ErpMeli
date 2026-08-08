<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\CronTaskLaneSelector;

$selector = new CronTaskLaneSelector();
$finished = [
    'notification_spool' => null,
    'notification_fallback' => null,
    'manual_campaign' => null,
    'order_enrichment' => null,
];
$counts = array_fill_keys(array_keys($finished), 0);
$directedMisses = 0;
$maxDirectedGap = 0;
$lastDirectedCycle = -1;

for ($cycle = 0; $cycle < 30; $cycle++) {
    $items = [
        ['key' => 'notification_spool', 'lane' => 'local', 'api' => false, '_last_finished_at' => $finished['notification_spool']],
        ['key' => 'notification_fallback', 'lane' => 'urgent', 'api' => true, '_last_finished_at' => $finished['notification_fallback']],
        ['key' => 'manual_campaign', 'lane' => 'directed', 'api' => true, '_last_finished_at' => $finished['manual_campaign'], '_directed_missed_cycles' => $directedMisses],
        ['key' => 'order_enrichment', 'lane' => 'normal', 'api' => true, '_last_finished_at' => $finished['order_enrichment']],
    ];
    $selected = $selector->select($items, 1, 1);
    $key = (string) ($selected[0]['key'] ?? '');
    if (!array_key_exists($key, $counts)) {
        throw new RuntimeException('El selector produjo un candidato desconocido.');
    }
    $counts[$key]++;
    $finished[$key] = gmdate('Y-m-d H:i:s', 1785510000 + $cycle);

    if ($key === 'manual_campaign') {
        if ($lastDirectedCycle >= 0) {
            $maxDirectedGap = max($maxDirectedGap, $cycle - $lastDirectedCycle);
        }
        $lastDirectedCycle = $cycle;
        $directedMisses = 0;
    } else {
        $directedMisses++;
    }
}

if (min($counts) < 5) {
    throw new RuntimeException('Algún carril quedó sin servicio suficiente en treinta ciclos: ' . json_encode($counts));
}
if ($maxDirectedGap > 4) {
    throw new RuntimeException('La campaña dirigida quedó más de cuatro ciclos sin turno.');
}

echo 'PASS cron_fairness_30_cycles_22828 ' . json_encode($counts, JSON_UNESCAPED_SLASHES) . PHP_EOL;
