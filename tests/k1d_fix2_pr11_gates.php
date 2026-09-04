<?php

declare(strict_types=1);

require __DIR__ . '/k1b_bootstrap.php';

require_once __DIR__ . '/../app/Services/CriticalApiAlertEmailService.php';

use App\Services\CriticalApiAlertEmailService;

$calibration = file_get_contents(__DIR__ . '/../app/Views/settings/api_workload.php');
$apiHealth = file_get_contents(__DIR__ . '/../app/Views/settings/api_health.php');
$incidentsShell = file_get_contents(__DIR__ . '/../app/Views/settings/api_health_incidents_shell.php');
$settingsSection = file_get_contents(__DIR__ . '/../app/Views/settings/section.php');
$settingsDefinitions = file_get_contents(__DIR__ . '/../app/Repositories/SettingsDefinitionRepository.php');
$settingsController = file_get_contents(__DIR__ . '/../app/Controllers/SettingsController.php');
$index = file_get_contents(__DIR__ . '/../public/index.php');
$email = file_get_contents(__DIR__ . '/../app/Services/CriticalApiAlertEmailService.php');

foreach (compact('calibration', 'apiHealth', 'incidentsShell', 'settingsSection', 'settingsDefinitions', 'settingsController', 'index', 'email') as $name => $content) {
    k1b_assert(is_string($content) && $content !== '', 'read_' . $name);
}

$navStart = strpos($calibration, '<nav class="cron-view-tabs"');
$navEnd = $navStart === false ? false : strpos($calibration, '</nav>', $navStart);
$nav = ($navStart !== false && $navEnd !== false) ? substr($calibration, $navStart, $navEnd - $navStart) : '';
k1b_assert($nav !== '' && substr_count($nav, '<a ') === 4, 'K1D_FIX2_EXACTLY_FOUR_CRON_TABS');
k1b_assert(str_contains($nav, 'Resumen') && str_contains($nav, 'Calibración') && str_contains($nav, 'Procesar ahora') && str_contains($nav, 'Salud y alertas'), 'K1D_FIX2_TAB_NAMES');

$budgetFormStart = strpos($calibration, 'id="call-budget-form"');
$budgetFormEnd = $budgetFormStart === false ? false : strpos($calibration, '</form>', $budgetFormStart);
$budgetForm = ($budgetFormStart !== false && $budgetFormEnd !== false) ? substr($calibration, $budgetFormStart, $budgetFormEnd - $budgetFormStart) : '';
k1b_assert($budgetForm !== '' && str_contains($budgetForm, 'automation_max_api_calls_per_cycle'), 'K1D_FIX2_CALL_BUDGET_ONLY_PRIMARY_EDITABLE');
k1b_assert(!str_contains($budgetForm, 'target_http_per_minute') && !str_contains($budgetForm, 'billing_429_backoff'), 'K1D_FIX2_PRIMARY_FORM_NO_TECHNICAL_RHYTHM');
k1b_assert(str_contains($calibration, '<details class="panel settings-advanced rhythm-advanced-settings">') && str_contains($calibration, 'id="rhythm-profile-form"'), 'K1D_FIX2_TECHNICAL_CONTROLS_IN_CLOSED_DETAILS');
k1b_assert(!str_contains($calibration, 'critical-email-test-form') && !str_contains($calibration, 'alerts_email_to'), 'K1D_FIX2_EMAIL_REMOVED_FROM_CALIBRATION');

k1b_assert(str_contains($apiHealth, 'Alertas críticas por email'), 'K1D_FIX2_API_HEALTH_EMAIL_SECTION');
k1b_assert(str_contains($apiHealth, 'alerts_email_enabled') && str_contains($apiHealth, 'alerts_email_to') && str_contains($apiHealth, 'alerts_email_cooldown_minutes'), 'K1D_FIX2_API_HEALTH_EMAIL_FIELDS');
k1b_assert(str_contains($apiHealth, 'alerts_email_notify_429') && str_contains($apiHealth, 'alerts_email_notify_auth'), 'K1D_FIX2_API_HEALTH_EMAIL_SCOPE_FIELDS');
k1b_assert(str_contains($apiHealth, '/settings/api-health/email-test') && str_contains($settingsController, 'sendCriticalApiAlertTestEmail'), 'K1D_FIX2_EMAIL_TEST_IN_HEALTH');
k1b_assert(str_contains($settingsController, 'saveCriticalApiAlertSettings') && str_contains($index, '/settings/api-health/email-settings'), 'K1D_FIX2_EMAIL_SAVE_ROUTE');

k1b_assert(str_contains($settingsDefinitions, "automation.max_api_calls_per_cycle") && str_contains($settingsDefinitions, "'/settings/cron/rhythm'"), 'K1D_FIX2_CALL_BUDGET_SINGLE_UI_LINK');
k1b_assert(str_contains($settingsDefinitions, "alerts.email.enabled") && str_contains($settingsDefinitions, "'/settings/api-health'"), 'K1D_FIX2_EMAIL_SINGLE_UI_LINK');
k1b_assert(str_contains($settingsSection, 'managed_elsewhere') && str_contains($settingsSection, 'Valor actual:') && str_contains($settingsSection, 'Cambiar en su módulo'), 'K1D_FIX2_GENERIC_SETTINGS_READONLY_LINK');

k1b_assert(str_contains($incidentsShell, 'La lectura no está certificada'), 'K1D_FIX2_INCIDENTS_ASYNC_FALSE_NOT_CERTIFIED');
k1b_assert(strpos($incidentsShell, 'La lectura no está certificada') < strpos($incidentsShell, 'No hay incidentes para esta lectura certificada'), 'K1D_FIX2_INCIDENTS_FALSE_BRANCH_BEFORE_ZERO');

k1b_assert(str_contains($email, 'humanContextLabels') && str_contains($email, 'Empresa: ') && str_contains($email, 'Cuenta: '), 'K1D_FIX2_EMAIL_HUMAN_LABELS');
k1b_assert(str_contains($email, 'Content-Type: text/plain; charset=UTF-8') && str_contains($email, "preg_replace('/[\\r\\n]+/'"), 'K1D_FIX2_EMAIL_SAFE_UTF8_HEADERS');
k1b_assert(str_contains($email, 'claimSendLease') && str_contains($email, 'rowCount() === 1'), 'K1D_FIX2_EMAIL_ATOMIC_CLAIM_STILL_PRESENT');

$service = new CriticalApiAlertEmailService(static fn(): bool => true);
$message = new ReflectionMethod($service, 'message');
[$subject, $body] = $message->invoke($service, [
    'company_id' => 77,
    'meli_account_id' => 88,
    'method' => 'GET',
    'endpoint_path' => '/orders/search',
    'endpoint_key' => 'orders_search',
    'http_status' => 429,
    'retry_after' => 120,
    'request_id' => "safe-request\r\nInjected: no",
    'execution_source' => 'queue_v4',
    'job_type' => 'sales',
    'source_work_id' => 'work-123',
    'next_safe_at' => '2026-09-03 16:00:00',
]);
k1b_assert($subject === 'ERP Meli: alerta API HTTP 429', 'K1D_FIX2_EMAIL_SUBJECT');
k1b_assert(str_contains($body, 'Empresa: ID técnico 77') && str_contains($body, 'Cuenta: ID técnico 88'), 'K1D_FIX2_EMAIL_FALLBACK_HUMAN_LINES');
k1b_assert(str_contains($body, 'Operación/endpoint: orders_search') && str_contains($body, 'Trabajo: sales') && str_contains($body, 'Work ID: work-123'), 'K1D_FIX2_EMAIL_OPERATION_CONTEXT');
k1b_assert(!str_contains($body, "\r") && !str_contains($body, "\nInjected:"), 'K1D_FIX2_EMAIL_BODY_SANITIZED');

$headers = new ReflectionMethod($service, 'headers');
$headerText = $headers->invoke($service);
k1b_assert(is_string($headerText) && str_contains($headerText, 'MIME-Version: 1.0') && str_contains($headerText, 'charset=UTF-8'), 'K1D_FIX2_EMAIL_HEADERS_UTF8');

echo "STATUS=PASS K1D_FIX2_PR11_GATES\n";
echo "REAL_MELI_HTTP=0\n";
echo "REAL_EMAIL_SENT=0\n";
echo "SSH_USED=NO\n";
echo "FTP_USED=NO\n";
