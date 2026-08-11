<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$service = (string) file_get_contents($root . '/app/Services/CronV3RetirementForV4Service.php');
$controller = (string) file_get_contents($root . '/app/Controllers/SettingsController.php');
$view = (string) file_get_contents($root . '/app/Views/settings/cron_shell.php');
$js = (string) file_get_contents($root . '/public/assets/app.js');
$routes = (string) file_get_contents($root . '/public/index.php');
$oracle = $root . '/docs/release-authorities/V3_RETIREMENT_FOR_V4_ORACLE.sql';
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

try {
    $assert(hash_file('sha256', $oracle) === '0c17bb3487b223bcc69a2d3470a1d8e88f065dc2ac6a388b0939a611be451861', 'oracle_sha_invalid');
    $assert(substr_count($routes, '/settings/cron/v3-setup/prepare-safe-config') === 1, 'new_post_route_added');
    $assert(str_contains($controller, '$this->requireAdminPermanent();'), 'permanent_admin_gate_missing');
    $assert(str_contains($controller, '$this->assertSameOrigin();'), 'same_origin_gate_missing');
    $assert(str_contains($controller, "Csrf::validate(\$_POST['_token'] ?? null);"), 'csrf_gate_missing');
    $assert(str_contains($controller, 'AdministrativeReauthenticationService'), 'password_reauthentication_missing');
    $assert(str_contains($controller, "operation'] ?? '') === 'retire_for_v4'"), 'operation_dispatch_missing');
    $assert(str_contains($view, 'Retirar autoridad V3 para preparar V4'), 'action_label_missing');
    $assert(str_contains($view, 'No borrará trabajos históricos, ventas ni intentos'), 'human_warning_missing');
    $assert(str_contains($view, 'name="confirmation_phrase"'), 'confirmation_phrase_missing');
    $assert(str_contains($view, 'name="admin_password"'), 'admin_password_missing');
    $assert(str_contains($view, 'type="submit" disabled'), 'button_not_disabled_by_default');
    $assert(str_contains($js, "form.dataset.preflightOk === '1'"), 'preflight_ui_gate_missing');
    $assert(str_contains($js, 'form.dataset.confirmationPhrase'), 'phrase_ui_gate_missing');
    $assert(str_contains($js, "Boolean(password?.value)"), 'password_ui_gate_missing');
    $assert(str_contains($service, "public const REQUIRED_APP_VERSION = '2.36.5'"), 'release_gate_invalid');
    $assert(str_contains($service, "public const LOCK_NAME = 'erp_meli_v3_retirement_for_v4_2363'"), 'lock_authority_invalid');
    $assert(str_contains($service, 'SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED'), 'read_committed_missing');
    $assert(str_contains($service, 'SELECT GET_LOCK(?,0)'), 'advisory_lock_missing');
    $assert(str_contains($service, 'FOR UPDATE'), 'row_lock_missing');
    $assert(str_contains($service, 'PDO::ATTR_PERSISTENT => false'), 'fresh_nonpersistent_pdo_missing');
    $assert(!preg_match('/\bDELETE\s+FROM\b/i', $service), 'delete_statement_present');
    foreach (['orders', 'sales', 'packs', 'queue_core_jobs', 'storage/raw', 'MeliApiClient'] as $forbidden) {
        $assert(!str_contains($service, $forbidden), 'forbidden_authority_present:' . $forbidden);
    }
    foreach (['cron_v3_work', 'cron_v3_attempts'] as $historical) {
        $assert(!preg_match('/(?:UPDATE|INSERT\s+INTO|DELETE\s+FROM)\s+' . preg_quote($historical, '/') . '/i', $service), 'historical_dml_present:' . $historical);
    }
    $assert(substr_count($service, "UPDATE cron_v3_queue_ownership") === 1, 'ownership_update_count_invalid');
    $assert(str_contains($service, 'INSERT INTO app_settings'), 'settings_upsert_missing');
    $assert(str_contains($service, "'before_first_mutation'"), 'first_failpoint_missing');
    $assert(str_contains($service, "'before_commit'"), 'commit_failpoint_missing');
    $assert(str_contains($service, 'business_rows_changed'), 'business_receipt_missing');
    $assert(str_contains($service, 'v3_retirement_already_completed'), 'idempotence_block_missing');
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL cron_v3_retirement_admin_2365 ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

echo 'PASS cron_v3_retirement_admin_2365 checks=' . $checks . PHP_EOL;
