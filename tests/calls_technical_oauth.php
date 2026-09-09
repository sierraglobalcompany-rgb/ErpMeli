<?php
declare(strict_types=1);
namespace App\Core {
    final class Auth { public static function id(): int { return 9; } }
    final class Env { public static function get(string $key, mixed $default = null): string { return 'local-fixture'; } }
    final class Crypto { public static function encrypt(string $value): string { return 'encrypted-local-fixture'; } }
    final class Database {
        public static function connection(): \PDO { return $GLOBALS['oauthPdo']; }
        public static function connectionFresh(): \PDO { return $GLOBALS['oauthPdo']; }
    }
}
namespace App\Services {
    final class CapacityPolicyService { public function snapshot(string $module): array { return ['current'=>55, 'ceiling'=>55]; } }
    final class MeliEmergencyStopService { public function assertAllowed(): void {} }
    final class MeliApiClient {
        public static int $exchanges = 0;
        public static int $profiles = 0;
        public function __construct(private int $accountId) {}
        public function exchangeOAuthToken(array $data, array $metadata): array {
            \k1b_assert($this->accountId === 0 && $metadata['source'] === 'web' && $metadata['company_id'] === 7 && $metadata['oauth_state_id'] === 5, 'initial_oauth_has_real_state_scope_not_fake_v4');
            \k1b_assert(ApiExecutionMetadataContext::technicalOperation() === 'initial_oauth', 'initial_oauth_requires_private_capability');
            $id = str_repeat('c',40);
            \App\QueueV4Clean\QueueV4CleanCycleBudget::reserve($id, 'web');
            \App\QueueV4Clean\QueueV4CleanCycleBudget::enteringTransport($id);
            self::$exchanges++;
            return ['access_token'=>'local-fixture', 'refresh_token'=>'local-fixture', 'user_id'=>'123', 'expires_in'=>3600];
        }
        public function get(string $path): array {
            \k1b_assert($path === '/users/me' && $this->accountId === 11 && ApiExecutionMetadataContext::technicalOperation() === 'oauth_profile', 'optional_profile_is_exact_private_capability');
            \k1b_assert(ApiExecutionMetadataContext::current()['company_id'] === 7 && ApiExecutionMetadataContext::current()['account_id'] === 11, 'optional_profile_is_persisted_account_scope');
            $id = str_repeat('d',40);
            \App\QueueV4Clean\QueueV4CleanCycleBudget::reserve($id, 'web');
            \App\QueueV4Clean\QueueV4CleanCycleBudget::enteringTransport($id);
            self::$profiles++;
            return ['nickname'=>'Fixture'];
        }
    }
    final class Logger { public static function write(mixed ...$args): void { throw new \LogicException('unexpected_profile_failure'); } }
}
namespace {
    require __DIR__ . '/k1b_bootstrap.php';
    use App\QueueV4Clean\QueueV4CleanCycleBudget as B;
    use App\Services\ApiExecutionMetadataContext as Metadata;
    use App\Services\MeliApiClient;
    final class OAuthStatement extends PDOStatement {
        public function __construct(private string $sql) {}
        public function execute(?array $params = null): bool { return true; }
        public function rowCount(): int { return 1; }
        public function fetchColumn(int $column = 0): mixed { return 7; }
        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
            return str_contains($this->sql, 'SELECT * FROM meli_oauth_states')
                ? ['id'=>5, 'company_id'=>7, 'account_name'=>'Fixture'] : false;
        }
    }
    final class OAuthPDO extends PDO {
        private bool $transaction = false;
        public int $commits = 0;
        public function __construct() {}
        public function prepare(string $query, array $options = []): PDOStatement|false { return new OAuthStatement($query); }
        public function inTransaction(): bool { return $this->transaction; }
        public function beginTransaction(): bool { $this->transaction = true; return true; }
        public function commit(): bool { $this->transaction = false; $this->commits++; return true; }
        public function rollBack(): bool { $this->transaction = false; return true; }
        public function lastInsertId(?string $name = null): string|false { return '11'; }
    }
    $GLOBALS['oauthPdo'] = new OAuthPDO();
    $service = new App\Services\OAuthService();
    k1b_assert($service->complete('fixture-code', 'fixture-state') === 11, 'initial_oauth_completes_after_one_token_call');
    k1b_assert(MeliApiClient::$exchanges === 1 && MeliApiClient::$profiles === 0 && $GLOBALS['oauthPdo']->commits === 1, 'one_slot_does_not_reopen_budget_for_profile');
    k1b_assert(B::snapshot()['limit'] === 0 && Metadata::technicalOperation() === null, 'initial_oauth_restores_owned_context');
    B::start(3, 'automatic', microtime(true)+20);
    $before = B::snapshot();
    k1b_assert($service->complete('fixture-code', 'fixture-state') === 11, 'nested_oauth_completes');
    k1b_assert(MeliApiClient::$exchanges === 2 && MeliApiClient::$profiles === 1, 'profile_can_only_consume_existing_remaining_slot');
    k1b_assert(B::snapshot()['limit'] === 3 && B::snapshot()['used'] === 2 && B::snapshot()['owner'] === 'automatic' && B::snapshot()['deadline'] === $before['deadline'], 'nested_oauth_preserves_and_charges_parent_budget');
    B::clear();
    echo "CALLS_TECHNICAL_OAUTH_OK\n";
}
