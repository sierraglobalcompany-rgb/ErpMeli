<?php
declare(strict_types=1);

namespace App\Core {
    final class Database {
        public static function connection(): \PDO { return $GLOBALS['technicalPdo']; }
    }
    final class Env {
        public static function bool(string $key, bool $default = false): bool { return false; }
        public static function get(string $key, mixed $default = null): string { return 'local-fixture'; }
    }
}
namespace App\Services {
    final class CapacityPolicyService {
        public static int $current = 3;
        public function snapshot(string $module): array { return ['current'=>self::$current, 'ceiling'=>55]; }
    }
    final class AppSettingsService {
        public function int(string $key, int $default): int { return $default; }
    }
    // Only external control-state persistence is replaced; run(), nonce context,
    // metadata capability and the physical counter below are production code.
    final class EmergencyControlService {
        public bool $stopped = true;
        public int $completed = 0;
        public int $failed = 0;
        public function automationStopped(): bool { return $this->stopped; }
        public function apiStopped(): bool { return true; }
        public function status(): array { return ['canary'=>['state'=>'ready', 'expires_at'=>time()+60, 'used_calls'=>0]]; }
        public function reserveApiCanary(int $accountId, string $user): string { return 'private-canary-nonce'; }
        public function completeApiCanarySuccess(mixed ...$args): void { $this->completed++; }
        public function failApiCanaryAndBlock(mixed ...$args): void { $this->failed++; }
        public function reserveEmergencyOAuthRefresh(int $accountId, string $user): string { return 'private-fixture-nonce'; }
        public function completeEmergencyOAuthRefreshSuccess(mixed ...$args): void { $this->completed++; }
        public function failEmergencyOAuthRefreshAndBlock(mixed ...$args): void { $this->failed++; }
    }
    final class MeliApiClient {
        public function __construct(private int $accountId) {}
        public function get(string $path, array $query = [], array $metadata = []): array {
            \App\QueueV4Clean\QueueV4CleanCycleBudget::assertActive();
            \k1b_assert(ApiExecutionMetadataContext::technicalOperation() === 'emergency_canary', 'canary_private_capability');
            \k1b_assert(EmergencyCanaryTransportContext::reservationNonce() !== '', 'canary_nonce_preserved');
            \k1b_assert($path === '/users/me' && $this->accountId === 11, 'canary_exact_endpoint_account');
            $id = str_repeat('b',40);
            \App\QueueV4Clean\QueueV4CleanCycleBudget::reserve($id, 'manual_emergency_canary');
            \App\QueueV4Clean\QueueV4CleanCycleBudget::enteringTransport($id);
            return ['id'=>'123'];
        }
        public function lastResponseMetadata(): array { return ['status'=>200]; }
    }
}
namespace {
    require __DIR__ . '/k1b_bootstrap.php';
    use App\QueueV4Clean\QueueV4CleanCycleBudget as B;
    use App\Services\ApiExecutionMetadataContext as Metadata;
    use App\Services\EmergencyControlService;
    use App\Services\EmergencyOAuthRefreshService;
    use App\Services\EmergencyOAuthRefreshTransportContext;
    final class TechnicalStatement extends PDOStatement {
        public function execute(?array $params = null): bool { return true; }
        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
            return ['id'=>11, 'company_id'=>7, 'meli_user_id'=>'123', 'nickname'=>'Fixture', 'token_present'=>1, 'expires_at'=>gmdate('Y-m-d H:i:s',time()+3600)];
        }
        public function fetchColumn(int $column = 0): mixed { return 0; }
    }
    final class TechnicalPDO extends PDO {
        public int $queries = 0;
        public function __construct() {}
        public function prepare(string $query, array $options = []): PDOStatement|false { return new TechnicalStatement(); }
        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
            $this->queries++;
            throw new LogicException('retired_readiness_must_not_query');
            return new TechnicalStatement();
        }
    }
    $GLOBALS['technicalPdo'] = new TechnicalPDO();
    $control = new EmergencyControlService();
    $refreshCalls = 0;
    $service = new EmergencyOAuthRefreshService(
        $control,
        static fn (int $id): array => ['id'=>$id, 'company_id'=>7, 'status'=>'connected', 'meli_user_id'=>'123', 'refresh_token_encrypted'=>'opaque-local-fixture', 'expires_at'=>'2000-01-01 00:00:00', 'refresh_version'=>2, 'nickname'=>'Fixture'],
        static function (int $id) use (&$refreshCalls): array {
            B::assertActive();
            k1b_assert(B::snapshot()['owner'] === 'manual' && B::snapshot()['limit'] === 1, 'emergency_refresh_must_have_one_shared_slot');
            k1b_assert(Metadata::technicalOperation() === 'emergency_oauth', 'emergency_refresh_private_capability');
            k1b_assert(EmergencyOAuthRefreshTransportContext::reservationNonce() !== '', 'existing_emergency_nonce_is_preserved');
            k1b_assert(Metadata::current()['company_id'] === 7 && Metadata::current()['meli_account_id'] === $id, 'emergency_scope_metadata_preserved');
            $attempt = str_repeat('a',40);
            B::reserve($attempt, 'manual_emergency_oauth_refresh'); B::enteringTransport($attempt);
            $refreshCalls++;
            return [];
        },
        static fn (int $id): array => ['expires_at'=>gmdate('Y-m-d H:i:s',time()+3600), 'refresh_version'=>3],
        static fn (int $id) => null,
    );
    $result = $service->run(11, 'fixture-admin');
    k1b_assert($result['account_id'] === 11 && $refreshCalls === 1 && $control->completed === 1, 'explicit_emergency_refresh_still_completes');
    k1b_assert(B::snapshot()['limit'] === 0 && Metadata::technicalOperation() === null && EmergencyOAuthRefreshTransportContext::reservationNonce() === '', 'successful_refresh_restores_all_contexts');
    $control->stopped = false;
    try { $service->run(11, 'fixture-admin'); throw new LogicException('preflight_should_reject'); }
    catch (RuntimeException) {}
    k1b_assert($refreshCalls === 1 && B::snapshot()['limit'] === 0, 'failed_preflight_never_dispatches_or_leaks_budget');
    $control->stopped = true;
    $canary = new App\Services\EmergencyApiCanaryService($control);
    $canaryResult = $canary->run(11, 'fixture-admin');
    k1b_assert($canaryResult['http_status'] === 200 && $control->completed === 2, 'explicit_canary_still_completes');
    k1b_assert(B::snapshot()['limit'] === 0 && Metadata::technicalOperation() === null && App\Services\EmergencyCanaryTransportContext::reservationNonce() === '', 'canary_restores_all_contexts');
    $readiness = new App\QueueV4Clean\QueueV4CleanReadinessService($GLOBALS['technicalPdo']);
    // Legacy bulk certification is retired, regardless of capacity. Real
    // prepare/check locking and three explicit 1-call requests are exercised
    // by calls_readiness_contract.php with schema 301 and a second PDO.
    foreach ([1, 3, 100] as $current) {
        App\Services\CapacityPolicyService::$current = $current;
        try { $readiness->certify(9); throw new LogicException('bulk_readiness_must_reject'); }
        catch (RuntimeException $error) { k1b_assert($error->getMessage() === 'queue_v4_clean_readiness_explicit_steps_required', 'readiness_requires_explicit_steps'); }
        k1b_assert($GLOBALS['technicalPdo']->queries === 0 && B::snapshot()['limit'] === 0, 'retired_readiness_has_no_lock_or_partial_run');
    }
    echo "CALLS_TECHNICAL_LAUNCHERS_OK\n";
}
