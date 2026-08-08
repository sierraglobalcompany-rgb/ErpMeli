<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\Migrator;

$root = dirname(__DIR__);
$migration015 = $root . '/database/migrations/015_sync_products_claims_2_1.sql';
$sql = (string) file_get_contents($migration015);
$lf = (string) preg_replace("/\r\n?|\n/", "\n", $sql);
$crlf = (string) preg_replace("/\r\n?|\n/", "\r\n", $sql);

$reflection = new ReflectionClass(Migrator::class);
$migrator = $reflection->newInstanceWithoutConstructor();
$method = $reflection->getMethod('isPortableLineEndingChecksum');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$assert(
    $method->invoke($migrator, $migration015, hash('sha256', $lf)) === true,
    'El migrador debe aceptar el hash LF de la migración 015 aplicada.'
);
$assert(
    $method->invoke($migrator, $migration015, hash('sha256', $crlf)) === true,
    'El migrador debe aceptar el hash CRLF equivalente de la migración 015 aplicada.'
);
$assert(
    $method->invoke($migrator, $migration015, hash('sha256', $lf . "\n-- cambio real")) === false,
    'El migrador no debe aceptar cambios reales de contenido como si fueran finales de línea.'
);

$migratorSource = (string) file_get_contents($root . '/app/Services/Migrator.php');
$assert(
    str_contains($migratorSource, 'checksum_line_ending_equivalent')
    && str_contains($migratorSource, 'sql_executed')
    && str_contains($migratorSource, 'markDriftPreservingChecksum'),
    'La reconciliación debe quedar auditada y no debe sobrescribir el checksum original ante drift real.'
);

echo "migration_line_ending_drift_2298_ok\n";
