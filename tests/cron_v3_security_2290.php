<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/erp-meli-cron-v3-security-' . bin2hex(random_bytes(6));
define('ERP_SHARED_ROOT', $temporary);
require $root . '/vendor/autoload.php';

use App\Services\WebhookSpoolService;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path)) {
        @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $remove($path . '/' . $entry);
        }
    }
    @rmdir($path);
};

try {
    $oauth = (string) file_get_contents($root . '/app/Services/OAuthService.php');
    $refresh = (string) file_get_contents($root . '/app/Services/OAuthTokenRefreshService.php');
    $budget = (string) file_get_contents($root . '/app/Services/ApiBudgetService.php');
    $receiver = (string) file_get_contents($root . '/app/Services/WebhookService.php');
    $spoolSource = (string) file_get_contents($root . '/app/Services/WebhookSpoolService.php');
    $publicReceiver = (string) file_get_contents($root . '/public/webhook_mercadolibre.php');
    $cron = (string) file_get_contents($root . '/jobs/process_sync_queue.php');
    $migration = (string) file_get_contents($root . '/database/migrations/240_cron_v3_security_containment_2_29_0.sql');

    $assert(!str_contains($oauth, 'company_id=VALUES(company_id)'), 'OAuth todavía puede trasladar una cuenta entre empresas.');
    $assert(
        str_contains($oauth, 'WHERE meli_user_id=:user LIMIT 1 FOR UPDATE')
        && str_contains($oauth, "(int) \$existingAccount['company_id'] !== \$companyId")
        && str_contains($oauth, 'otra empresa')
        && str_contains($oauth, 'WHERE id=:id AND company_id=:company'),
        'OAuth debe bloquear el conflicto de empresa dentro de la transacción.'
    );
    $assert(
        str_contains($refresh, 'WHERE meli_account_id=?')
        && !str_contains($refresh, 'company_id=')
        && !str_contains($refresh, 'meli_user_id='),
        'El refresh OAuth debe permanecer acotado a la cuenta sin mutar tenant o identidad ML.'
    );

    $assert(
        strpos($budget, 'if (!$this->enabled())') < strpos($budget, 'if (!$this->schemaReady())')
        && str_contains($budget, 'El presupuesto API está habilitado, pero su esquema no está instalado')
        && str_contains($budget, 'LIMIT 1 FOR UPDATE')
        && str_contains($budget, 'request_count<:request_limit_guard'),
        'El presupuesto debe fallar cerrado y reservar bajo locks atómicos.'
    );
    $assert(
        str_contains($migration, 'uq_api_budget_window') || str_contains((string) file_get_contents($root . '/database/migrations/061_api_budget_blocking_2_9_2.sql'), 'uq_api_budget_window'),
        'La reserva atómica requiere la llave única de ventana.'
    );
    $assert(
        str_contains($migration, "'structural'") && !str_contains($migration, "'security'"),
        'La migración debe usar un contract_kind aceptado por el esquema existente.'
    );

    $validateAt = strpos($publicReceiver, 'validateIngress($raw)');
    $accountAt = strpos($publicReceiver, 'validateLinkedAccount((int) $validation[\'user_id\'])');
    $appendAt = strpos($publicReceiver, 'append($raw)');
    $assert(
        $validateAt !== false && $accountAt !== false && $appendAt !== false
        && $validateAt < $accountAt && $accountAt < $appendAt,
        'El endpoint público debe validar payload y cuenta antes del spool vivo.'
    );
    $assert(
        !str_contains($publicReceiver, 'Database::')
        && !str_contains($publicReceiver, 'WebhookService'),
        'La validación pública no debe abrir MariaDB ni procesar el evento.'
    );
    $assert(
        str_contains($spoolSource, 'function validateLinkedAccount(int $userId): array')
        && str_contains($spoolSource, 'INNER JOIN companies c ON c.id=a.company_id')
        && str_contains($spoolSource, 'WHERE a.meli_user_id=:user AND c.status=1')
        && str_contains($spoolSource, "'reason' => 'account_validation_unavailable'")
        && str_contains($spoolSource, "'http_status' => 503"),
        'La cuenta vinculada debe validarse por PDO con scoping y fallo cerrado.'
    );
    $assert(
        strpos($receiver, 'validateLinkedAccount($userId)') < strpos($receiver, 'if ($allowSpool'),
        'Ningún llamador de WebhookService puede hacer spool antes de validar la cuenta.'
    );
    $assert(
        str_contains($receiver, "'terminal' => true")
        && str_contains($spoolSource, "elseif (!empty(\$result['terminal']))")
        && str_contains($spoolSource, "'quarantined' => \$quarantined"),
        'Replay debe retirar poison hacia una cuarentena terminal.'
    );

    $lockAt = strpos($cron, 'cron_entry_early_lock()');
    $runtimeAt = strpos($cron, 'erp_prebootstrap_runtime_mode($earlyRuntimeState)');
    $retentionAt = strpos($cron, 'TechnicalRetentionCliService())->runStep');
    $assert(
        $lockAt !== false && $runtimeAt !== false && $retentionAt !== false
        && $lockAt < $runtimeAt && $runtimeAt < $retentionAt
        && str_contains($cron, 'retention_runtime_guard_active'),
        'Retención debe ejecutarse después del lock, pausas y freezes.'
    );
    $assert(
        preg_match('/taskState->finished\([\s\S]{0,500}\$runToken/', $cron) === 1,
        'Se perdió el fencing runToken aportado al cierre de taskState.'
    );

    putenv('MELI_CLIENT_ID=2290001');
    putenv('WEBHOOK_MAX_PAYLOAD_BYTES=262144');
    $spool = new WebhookSpoolService();
    $validRaw = json_encode([
        '_id' => 'cron-v3-valid',
        'topic' => 'orders_v2',
        'resource' => '/orders/22900001',
        'user_id' => 22900002,
        'application_id' => '2290001',
    ], JSON_UNESCAPED_SLASHES) ?: '{}';
    $valid = $spool->validateIngress($validRaw);
    $assert(!empty($valid['valid']), 'Una notificación conocida debe superar la validación pura.');
    $oversized = $spool->validateIngress(str_repeat('x', 262145));
    $assert(empty($oversized['valid']) && ($oversized['reason'] ?? '') === 'payload_too_large', 'El cap de entrada no está alineado.');
    $invalidJson = $spool->validateIngress('{');
    $assert(empty($invalidJson['valid']) && ($invalidJson['reason'] ?? '') === 'invalid_json', 'JSON inválido no debe entrar al spool.');

    $foreignRaw = json_encode([
        'topic' => 'orders_v2',
        'resource' => '/orders/22900002',
        'user_id' => 22900002,
        'application_id' => 'otra-app',
    ], JSON_UNESCAPED_SLASHES) ?: '{}';
    $foreign = $spool->validateIngress($foreignRaw);
    $assert(empty($foreign['valid']) && ($foreign['reason'] ?? '') === 'foreign_application', 'Debe rechazarse application_id ajeno.');
    $assert($spool->quarantine($foreignRaw, 'foreign_application'), 'No se pudo crear la cuarentena terminal.');
    $assert($spool->quarantine($foreignRaw, 'foreign_application'), 'La cuarentena repetida debe ser idempotente.');
    $foreignFormatted = json_encode(json_decode($foreignRaw, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    $assert($spool->quarantine($foreignFormatted, 'foreign_application'), 'La cuarentena canónica debe aceptar el mismo JSON formateado.');
    $quarantineFiles = glob($temporary . '/storage/spool/mercadolibre-webhooks-quarantine/quarantine-*.json') ?: [];
    $assert(count($quarantineFiles) === 1, 'Un mismo poison solo debe almacenarse una vez.');

    $liveDirectory = $temporary . '/storage/spool/mercadolibre-webhooks';
    if (!mkdir($liveDirectory, 0770, true) && !is_dir($liveDirectory)) {
        throw new RuntimeException('No fue posible crear el spool de prueba.');
    }
    $poisonRaw = json_encode([
        'topic' => 'topic_desconocido',
        'resource' => '/orders/22900003',
        'user_id' => 22900002,
        'application_id' => '2290001',
    ], JSON_UNESCAPED_SLASHES) ?: '{}';
    $record = json_encode(['spooled_at' => gmdate(DATE_ATOM), 'payload' => json_decode($poisonRaw, true)], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    file_put_contents($liveDirectory . '/webhooks-poison.jsonl', $record);
    $replay = $spool->replay(1, microtime(true) + 5.0);
    $assert((int) ($replay['quarantined'] ?? 0) === 1, 'Replay no clasificó el poison como terminal.');
    $assert($spool->pendingCount() === 0, 'Replay recicló el poison en la cola viva.');

    fwrite(STDOUT, "PASS cron_v3_security_2290\n");
} finally {
    putenv('MELI_CLIENT_ID');
    putenv('WEBHOOK_MAX_PAYLOAD_BYTES');
    $remove($temporary);
}
