<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/app/Services/CronV3RateGate.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$lockPosition = strpos($source, '$bucket = $this->lockBucket');
$incrementPosition = strpos($source, '$increment = $this->pdo->prepare');
$allowedPosition = strpos($source, "return ['allowed' => true", $incrementPosition !== false ? $incrementPosition : 0);
$commitPosition = $allowedPosition === false ? false : strrpos(substr($source, 0, $allowedPosition), '$this->pdo->commit();');

$assert($lockPosition !== false, 'No se bloquean todos los scopes antes de reservar.');
$assert($incrementPosition !== false && $incrementPosition > $lockPosition, 'Se incrementa antes de validar todos los scopes.');
$assert($commitPosition !== false && $commitPosition > $incrementPosition, 'La reserva no confirma despues de todos los incrementos.');
$assert(substr_count($source, 'FOR UPDATE') >= 2, 'Rate y circuit no comparten locks transaccionales.');
$assert(str_contains($source, "['1205', '1213']"), 'No se reconocen lock timeout y deadlock de MariaDB.');
$assert(str_contains($source, 'count($scopes) !== 5'), 'Schema 244 incompleto no falla cerrado.');
$assert(str_contains($source, 'if (!$scopedSchema)')
    && str_contains($source, 'Cron V3 scoped rate schema is unavailable.'),
    'Schema 241 sin 244 debe fallar cerrado, no degradar a bucket global.');
$assert(str_contains($source, 'state="half_open" AND probe_owner=?'),
    'Un exito concurrente anterior puede cerrar un circuito ajeno.');

echo "PASS cron_v3_rate_concurrency_contract_2291\n";
