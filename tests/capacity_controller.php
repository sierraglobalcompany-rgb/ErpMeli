<?php
declare(strict_types=1);
namespace App\Core {
    final class Auth {
        public static function requireRole(string $role): void { if (($GLOBALS['mode'] ?? '') === 'operator') throw new HttpException(403, 'admin required'); }
        public static function isTemporary(): bool { return ($GLOBALS['mode'] ?? '') === 'temporary'; }
        public static function id(): int { return 7; }
    }
    final class View {
        public static function render(string $view, array $data = [], bool $layout = true): void { $GLOBALS['rendered'] = ['view' => $view, 'data' => $data]; }
    }
}
namespace App\Services {
    final class ApiHealthAccessScope { public function snapshot(): array { throw new \RuntimeException('Fixture health unavailable'); } }
    final class CapacityChangeGuard {
        public function assertGlobalAuthorization(): void { if (($GLOBALS['mode'] ?? '') === 'revoked') throw new \App\Core\HttpException(403, 'global authorization required'); }
        public function increaseGate(): array { throw new \RuntimeException('Capacity saves must not request health.'); }
    }
    // Keep the real SafeErrorPresenter; only replace its filesystem logging boundary.
    final class Logger {
        public static function redactString(string $value): string { return '[redacted fixture]'; }
        public static function write(string $level, string $message, array $context = []): void {}
    }
    // Persistence spy: no database/network; shared policy has independent real MySQL tests.
    final class CapacityPolicyService {
        public const TECHNICAL_MAX = 100;
        public const DEFAULT_CEILING = 55;
        public static int $writes = 0;
        public static int $validations = 0;
        public function snapshot(string $module): array { return ['module' => $module, 'current' => 3, 'ceiling' => 55, 'revision' => 'rev-1']; }
        public function validatePair(mixed $current, mixed $ceiling): array {
            self::$validations++;
            if ($current === '101') throw new \InvalidArgumentException('invalid capacity');
            return ['current' => (int) $current, 'ceiling' => (int) $ceiling];
        }
        public function save(string $module, mixed $current, mixed $ceiling, string $revision): array {
            if (func_num_args() !== 4) throw new \RuntimeException('Capacity saves accept no health callback.');
            if ($revision !== 'rev-1') throw new \App\Core\HttpException(409, 'La capacidad cambió. Recargue y confirme los valores actuales.');
            if (($GLOBALS['mode'] ?? '') === 'unknown') throw new \RuntimeException('private database detail');
            self::$writes++;
            if (($GLOBALS['mode'] ?? '') === 'postwrite') throw new \RuntimeException('private post-commit detail');
            return compact('module', 'current', 'ceiling', 'revision');
        }
    }
}
namespace {
    require __DIR__ . '/k1b_bootstrap.php';
    if (!isset($argv[1])) {
        foreach (['prepare', 'confirm', 'cancel', 'missing', 'tamper', 'stale', 'invalid', 'operator', 'temporary', 'csrf', 'origin', 'increase', 'revoked', 'unknown', 'postwrite'] as $mode) {
            $process = proc_open([PHP_BINARY, __FILE__, $mode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]); $exit = proc_close($process);
            k1b_assert($exit === 0 && str_contains($out, 'PASS ' . $mode), $mode . ': ' . $out . $err);
        }
        echo "STATUS=PASS CAPACITY_CONTROLLER\n"; exit;
    }
    $mode = $argv[1];
    k1b_assert(method_exists(App\Controllers\SettingsController::class, 'saveManualCallBudget'), 'Manual handler is missing.');
    $_ENV['APP_URL'] = 'https://local.test';
    $_SERVER['HTTP_ORIGIN'] = $mode === 'origin' ? 'https://evil.test' : 'https://local.test';
    $_SESSION = ['_csrf' => 'test-csrf'];
    $proposal = ['nonce' => 'nonce-1', 'user_id' => 7, 'module' => 'manual', 'before' => ['current' => 3, 'ceiling' => 55], 'current' => 2, 'ceiling' => 100, 'revision' => 'rev-1', 'expires_at' => time() + 600];
    if ($mode === 'stale') $proposal['revision'] = 'old';
    if ($mode === 'increase') $proposal['current'] = 4;
    if ($mode !== 'missing') $_SESSION['capacity_proposal_manual'] = $proposal;
    $_POST = ['_token' => $mode === 'csrf' ? 'wrong' : 'test-csrf', 'capacity_action' => $mode === 'cancel' ? 'cancel' : 'confirm', 'confirmation_nonce' => $mode === 'tamper' ? 'wrong' : 'nonce-1'];
    if (in_array($mode, ['prepare', 'invalid'], true)) $_POST = ['_token' => 'test-csrf', 'manual_api_calls_per_step' => $mode === 'invalid' ? '101' : '2', 'manual_api_calls_ceiling' => '100', 'capacity_revision' => 'rev-1'];
    register_shutdown_function(static function () use ($mode): void {
        $expected = in_array($mode, ['confirm', 'increase', 'postwrite'], true) ? 1 : 0;
        k1b_assert(App\Services\CapacityPolicyService::$writes === $expected, 'Only an authenticated confirmed valid proposal may persist.');
        if (in_array($mode, ['unknown', 'postwrite'], true)) {
            $message = (string) ($_SESSION['_flash']['error'] ?? '');
            k1b_assert(str_contains($message, 'No se pudo confirmar') && !str_contains($message, 'No se guardó'), 'Unknown outcome must ask to verify current values, without claiming rollback.');
            k1b_assert(!str_contains($message, 'private') && str_contains($message, 'Código de diagnóstico'), 'Real presenter must hide technical details and retain diagnostic reference.');
        }
        if ($mode === 'stale') k1b_assert(str_contains((string) ($_SESSION['_flash']['error'] ?? ''), 'La capacidad cambió'), 'Known stale revision keeps actionable safe message.');
        if ($mode === 'prepare') {
            k1b_assert(App\Services\CapacityPolicyService::$validations === 1, 'Controller must delegate pair validation to the policy.');
            k1b_assert(($GLOBALS['rendered']['view'] ?? '') === 'settings/capacity_confirmation', 'First submission only renders confirmation.');
            k1b_assert(($_SESSION['capacity_proposal_manual']['current'] ?? null) === 2, 'Server holds confirmed candidate.');
        }
        if ($mode === 'cancel') k1b_assert(!isset($_SESSION['capacity_proposal_manual']), 'Cancel discards proposal.');
        echo 'PASS ' . $mode . "\n";
    });
    try { (new App\Controllers\SettingsController())->saveManualCallBudget(); }
    catch (App\Core\HttpException $error) { if (in_array($mode, ['prepare', 'confirm', 'cancel'], true)) throw $error; }
}
