<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$root = dirname(__DIR__);
require $root . '/bootstrap.php';

use App\Services\MeliApiKnowledgeService;

$options = getopt('', ['source::', 'root::', 'help']);
if (isset($options['help'])) {
    echo "Uso: php bin/meli_api_audit.php [--source=\"C:/ruta/Mercadolibre api\"] [--root=\"C:/ruta/ErpMeli\"]\n";
    echo "Genera auditoría y contratos materializados de API Mercado Libre sin llamar a Mercado Libre ni usar tokens.\n";
    exit(0);
}

$auditRoot = isset($options['root']) && is_string($options['root']) && trim($options['root']) !== ''
    ? trim($options['root'])
    : $root;

$defaultSource = 'C:/Users/felip/Documents/Mercadolibre api';
$source = isset($options['source']) && is_string($options['source']) && trim($options['source']) !== ''
    ? trim($options['source'])
    : $defaultSource;

$service = new MeliApiKnowledgeService();
$result = $service->generate($auditRoot, $source);

echo "Auditoría Mercado Libre API generada.\n";
echo "ERP: " . $result['root'] . "\n";
echo "Versión: " . $result['version'] . "\n";
echo "Fuente API: " . $result['source_dir'] . "\n";
echo "Contratos endpoint: " . $result['endpoint_count'] . "\n";
echo "Variables auditadas: " . $result['field_count'] . "\n";
echo "Riesgos detectados: " . $result['risk_count'] . "\n";
echo "Recursos: " . $result['resources_dir'] . "\n";
echo "Reportes:\n";
foreach ($result['generated_files'] as $file) {
    echo " - " . $file . "\n";
}
echo "JSON materializado:\n";
foreach ($result['json_files'] as $file) {
    echo " - " . $file . "\n";
}
