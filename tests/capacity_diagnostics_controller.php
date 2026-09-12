<?php
declare(strict_types=1);

// Real controller with doubles at authorization, database and diagnostic boundaries.
// No HTTP or database access. Real integration authorization is covered separately.
namespace App\Core {
    final class Auth {
        public static function requireRole(string $role): void { \diagnostics_fault('admin'); }
        public static function isTemporary(): bool { return false; }
    }
    final class Database { public static function connectionFresh(): object { return new \stdClass(); } }
    final class View {
        public static function render(string $view, array $data = [], bool $layout = true): void { $GLOBALS['rendered'] = compact('view', 'data'); }
    }
}
namespace App\Services {
    final class AppSettingsService {
        public function __construct(public readonly bool $strictReads = false) {}
        public function get(string $key, ?string $default = null): ?string {
            if ($this->strictReads) \diagnostics_fault('setting');
            return $default;
        }
    }
    final class CapacityPolicyService {
        public function snapshot(string $module): array {
            \diagnostics_fault('capacity');
            return ['module' => $module, 'current' => 55, 'ceiling' => 100, 'revision' => 'verified'];
        }
    }
    final class ApiRhythmPolicyService {
        public function __construct(private readonly AppSettingsService $settings = new AppSettingsService()) {}
        public function preview(): array { $this->settings->get('api.rhythm.profile', 'balanced'); \diagnostics_fault('rhythm'); return ['target_http_per_minute' => 20, 'profile' => 'balanced', 'observed_state' => ($GLOBALS['stage'] ?? '') === 'observed_unavailable' ? 'unavailable' : 'complete']; }
    }
    final class ApiHealthAccessScope {
        public function snapshot(): array { \diagnostics_fault('scope'); return ['company_ids' => [1], 'account_ids' => [2]]; }
    }
    final class CapacityChangeGuard {
        public function increaseGate(): array { \diagnostics_fault('gate'); return ['allowed' => false, 'message' => 'Runtime health gate remains active.']; }
    }
    final class ApiHealthService {
        public function dataAvailable(): bool { return !in_array($GLOBALS['stage'] ?? '', ['incidents_unavailable', 'incidents_partial_unavailable'], true); }
        public function classificationAvailable(): bool { return ($GLOBALS['stage'] ?? '') !== 'classification_unavailable'; }
        public function incidents(array $filters, int $limit): array { \diagnostics_fault('incidents'); return ($GLOBALS['stage'] ?? '') === 'incidents_partial_unavailable' ? [['http_status' => 429]] : []; }
    }
}
namespace App\QueueV4Clean {
    final class QueueV4CleanHealthSnapshotService {
        public function __construct(object $pdo) {}
        public function snapshot(mixed $account, array $companies, array $accounts): array { \diagnostics_fault('queue'); return ['totals' => ['ready' => 5], 'state' => 'healthy', 'ok' => true, 'protocol' => 'complete', 'snapshot_state' => ($GLOBALS['stage'] ?? '') === 'queue_incomplete' ? 'unavailable' : 'complete']; }
    }
}
namespace {
    require __DIR__ . '/k1b_bootstrap.php';
    function diagnostics_fault(string $stage): void {
        if (($GLOBALS['stage'] ?? '') !== $stage) return;
        if (($GLOBALS['failure'] ?? '') === 'auth') throw new App\Core\HttpException(403, 'Access revoked.');
        throw new RuntimeException('Private diagnostic dependency failure.');
    }
    if (!isset($argv[1])) {
        foreach (['get', 'preview'] as $endpoint) {
            $cases = [['healthy', 'none'], ['admin', 'auth'], ['rhythm', 'failure'], ['scope', 'failure'], ['queue', 'failure'], ['gate', 'failure'], ['incidents', 'failure'], ['scope', 'auth'], ['gate', 'auth'], ['incidents', 'auth'], ['observed_unavailable', 'failure'], ['classification_unavailable', 'failure'], ['queue_incomplete', 'failure']];
            $cases[] = ['setting', 'failure'];
            $cases[] = ['incidents_unavailable', 'failure'];
            $cases[] = ['incidents_partial_unavailable', 'failure'];
            if ($endpoint === 'get') $cases[] = ['capacity', 'failure'];
            foreach ($cases as [$stage, $failure]) {
                $process = proc_open([PHP_BINARY, __FILE__, $endpoint, $stage, $failure], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
                fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
                k1b_assert($exit === 0 && str_contains($out, 'PASS'), "$endpoint/$stage/$failure: $out$err");
            }
        }
        echo "STATUS=PASS CAPACITY_DIAGNOSTICS_CONTROLLER REAL_CONTROLLER=YES DIAGNOSTICS_DOUBLES=YES REAL_DB=NO REAL_HTTP=0\n";
        exit;
    }
    [$script, $endpoint, $stage, $failure] = $argv;
    $_SESSION = ['_csrf' => 'diagnostics-test'];
    http_response_code(200);
    $error = null;
    ob_start();
    try {
        $controller = new App\Controllers\SettingsController();
        if ($endpoint === 'get') $controller->cronRhythm(); else $controller->cronRhythmPreview();
    } catch (Throwable $caught) { $error = $caught; }
    $output = (string) ob_get_clean();
    if ($failure === 'auth') {
        k1b_assert($error instanceof App\Core\HttpException && $error->status === 403, 'Authorization errors must propagate, never become empty successful diagnostics.');
        k1b_assert(!isset($GLOBALS['rendered']) && $output === '', 'Rejected authorization must not render capacity or preview data.');
    } elseif ($stage === 'capacity') {
        k1b_assert($error instanceof RuntimeException && !isset($GLOBALS['rendered']), 'Mandatory capacity snapshot failures must not render invented capacity.');
    } elseif ($endpoint === 'get') {
        k1b_assert($error === null, 'Optional diagnostic failure must not prevent the verified capacity form.');
        $data = $GLOBALS['rendered']['data'] ?? [];
        k1b_assert(($data['capacity']['current'] ?? null) === 55, 'Page must receive verified capacity.');
        k1b_assert(($data['diagnosticsAvailable'] ?? null) === ($stage === 'healthy'), 'Page must explicitly distinguish available and unavailable diagnostics.');
        if ($stage !== 'healthy') k1b_assert(($data['rhythm'] ?? null) === [], 'Discard partial telemetry instead of mixing failure with defaults.');
    } else {
        k1b_assert($error === null, 'Optional preview failures must use the established JSON envelope.');
        $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        k1b_assert(($payload['ok'] ?? null) === ($stage === 'healthy'), 'Preview must not claim success for failed telemetry.');
        k1b_assert(http_response_code() === ($stage === 'healthy' ? 200 : 503), 'Preview failures return HTTP 503.');
        k1b_assert(!str_contains($output, 'Private diagnostic'), 'Preview must not disclose technical failure details.');
    }
    echo "PASS $endpoint/$stage/$failure\n";
}
