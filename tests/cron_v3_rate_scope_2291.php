<?php

declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$root = dirname(__DIR__);
$source = (string) file_get_contents($root . '/app/Services/CronV3RateGate.php');
$migration = (string) file_get_contents($root . '/database/migrations/244_cron_v3_rate_scope_authority_2_29_1.sql');

$assert(str_contains($source, "hash('sha256', 'cron_v3|global')"), 'Se perdio la autoridad global compatible con schema 241.');
$assert(str_contains($source, 'FOR UPDATE'), 'Los buckets no se bloquean dentro de la transaccion.');
$assert(str_contains($source, 'beginTransaction()') && str_contains($source, 'rowCount() !== 1'), 'La reserva atomica no falla cerrada.');
$assert(str_contains($source, 'blocked_until=CASE'), 'Retry-After puede ser acortado o no propagarse por scopes.');
$assert(!str_contains($source, 'blocked_until=NULL,version=version+1'), 'El reinicio de ventana no debe borrar Retry-After.');
$assert(str_contains($source, 'isRetryableTransactionError'), 'Se perdio el reintento acotado de deadlock.');
$assert(str_contains($source, 'half_open_probe_busy'), 'Se perdio el probe unico del circuit breaker.');
$assert(!preg_match('/access_token|refresh_token|authorization|client_secret/i', $migration), 'La migracion contiene campos de secretos.');

foreach (['scope_level', 'application_id', 'company_id', 'meli_account_id', 'endpoint_key', 'operation_key'] as $dimension) {
    $assert(str_contains($migration, $dimension), 'Falta dimension de rate: ' . $dimension);
}
$assert(str_contains($migration, 'information_schema.columns')
    && str_contains($migration, 'ALTER TABLE cron_v3_rate_buckets ADD COLUMN'),
    'La migracion 244 no es aditiva/idempotente.');
$assert(!preg_match('/\b(?:DROP|TRUNCATE|DELETE)\b/i', $migration), 'La migracion 244 contiene una operacion destructiva.');

$assert(str_contains($source, '$this->legacyGlobalBucket(),'), 'El lock global no es el primero.');
foreach (['global', 'application', 'account', 'endpoint', 'operation'] as $level) {
    $assert(str_contains($source, "'level' => '" . $level . "'"), 'Falta autoridad de nivel ' . $level . '.');
}
$assert(str_contains($source, "'application_id' => \$applicationId"), 'La aplicacion no queda identificada.');
$assert(str_contains($source, "'company_id' => \$work->companyId"), 'La empresa no queda identificada.');
$assert(str_contains($source, "'meli_account_id' => \$work->meliAccountId"), 'La cuenta no queda identificada.');
$assert(str_contains($source, "'endpoint_key' => \$endpointKey"), 'El endpoint no queda identificado.');
$assert(str_contains($source, "'operation_key' => \$operationKey"), 'La operacion no queda identificada.');
$assert(str_contains($source, "'order_exact' => ['endpoint_key' => 'orders.exact', 'operation_key' => 'order_exact']"), 'El perfil exacto de orden no es determinista.');
$assert(str_contains($source, 'default => null'), 'Un endpoint sin perfil confirmado no falla cerrado.');
$assert(str_contains($source, "preg_match('/^[A-Za-z0-9._:-]+$/', \$applicationId)"), 'application_id no se valida antes de formar scopes.');

echo "PASS cron_v3_rate_scope_2291\n";
