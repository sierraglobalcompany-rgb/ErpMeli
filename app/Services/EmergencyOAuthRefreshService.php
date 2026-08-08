<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

/** Ejecuta exclusivamente un POST /oauth/token para una cuenta seleccionada. */
final class EmergencyOAuthRefreshService
{
    public const SOURCE = 'manual_emergency_oauth_refresh';
    public const METHOD = 'POST';
    public const ENDPOINT = '/oauth/token';

    private EmergencyControlService $control;
    /** @var Closure(int):(array<string,mixed>|null) */
    private Closure $accountLoader;
    /** @var Closure(int):array<string,mixed> */
    private Closure $refresh;
    /** @var Closure(int):(array<string,mixed>|null) */
    private Closure $tokenStateLoader;
    /** @var Closure(int):void */
    private Closure $protectionCheck;

    /**
     * @param null|callable(int):(array<string,mixed>|null) $accountLoader
     * @param null|callable(int):array<string,mixed> $refresh
     * @param null|callable(int):(array<string,mixed>|null) $tokenStateLoader
     * @param null|callable(int):void $protectionCheck
     */
    public function __construct(
        ?EmergencyControlService $control = null,
        ?callable $accountLoader = null,
        ?callable $refresh = null,
        ?callable $tokenStateLoader = null,
        ?callable $protectionCheck = null
    ) {
        $this->control = $control ?? new EmergencyControlService();
        $this->accountLoader = $accountLoader !== null
            ? Closure::fromCallable($accountLoader)
            : fn (int $accountId): ?array => $this->loadAccount($accountId);
        $this->refresh = $refresh !== null
            ? Closure::fromCallable($refresh)
            : static fn (int $accountId): array => (new MeliApiClient($accountId))->refreshOAuthToken();
        $this->tokenStateLoader = $tokenStateLoader !== null
            ? Closure::fromCallable($tokenStateLoader)
            : fn (int $accountId): ?array => $this->loadTokenState($accountId);
        $this->protectionCheck = $protectionCheck !== null
            ? Closure::fromCallable($protectionCheck)
            : function (int $accountId): void {
                $this->assertProtectionsReadableAndClear($accountId);
            };
    }

    /** @return list<array{id:int,nickname:string,meli_user_id:string}> */
    public function eligibleAccounts(): array
    {
        $stmt = Database::connection()->query(
            "SELECT a.id,a.account_name,a.nickname,a.meli_user_id,t.expires_at
             FROM meli_accounts a
             INNER JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE a.status IN ('conectado','connected')
               AND a.meli_user_id IS NOT NULL AND LENGTH(TRIM(a.meli_user_id))>0
               AND t.refresh_token_encrypted IS NOT NULL
               AND LENGTH(TRIM(t.refresh_token_encrypted))>0
             ORDER BY a.id DESC"
        );
        $accounts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!$this->tokenNeedsRefresh((string) ($row['expires_at'] ?? ''))) {
                continue;
            }
            $accounts[] = [
                'id' => (int) $row['id'],
                'nickname' => trim((string) ($row['nickname'] ?? ''))
                    ?: trim((string) ($row['account_name'] ?? 'Cuenta Mercado Libre')),
                'meli_user_id' => trim((string) $row['meli_user_id']),
            ];
        }
        return $accounts;
    }

    /** @return array{account_id:int,nickname:string,expires_at:string,refresh_version:int} */
    public function run(int $accountId, string $actor): array
    {
        $account = $this->preflight($accountId);
        $nonce = $this->control->reserveEmergencyOAuthRefresh(
            $accountId,
            (string) $account['meli_user_id']
        );
        try {
            $metadata = [
                'source' => self::SOURCE,
                'job_type' => 'emergency_oauth_refresh',
                'company_id' => (int) $account['company_id'],
                'meli_account_id' => $accountId,
                'expected_meli_user_id' => (string) $account['meli_user_id'],
                'bulk' => false,
            ];
            EmergencyOAuthRefreshTransportContext::run(
                $nonce,
                fn (): array => ApiExecutionMetadataContext::run(
                    $metadata,
                    fn (): array => ($this->refresh)($accountId)
                )
            );
            $token = ($this->tokenStateLoader)($accountId);
            if (!is_array($token)) {
                throw new RuntimeException('No se pudo comprobar el token OAuth renovado.');
            }
            $expiresAt = trim((string) ($token['expires_at'] ?? ''));
            $refreshVersion = (int) ($token['refresh_version'] ?? 0);
            if (!$this->futureExpiry($expiresAt)
                || $refreshVersion <= (int) ($account['refresh_version'] ?? 0)) {
                throw new RuntimeException('La renovación OAuth no dejó una vigencia nueva verificable.');
            }
            $this->control->completeEmergencyOAuthRefreshSuccess(
                $nonce,
                $accountId,
                $expiresAt,
                $refreshVersion
            );
            return [
                'account_id' => $accountId,
                'nickname' => (string) $account['nickname'],
                'expires_at' => $expiresAt,
                'refresh_version' => $refreshVersion,
            ];
        } catch (Throwable $error) {
            $state = $this->control->status()['oauth_refresh'] ?? null;
            $status = is_array($state) && isset($state['http_status'])
                ? (int) $state['http_status']
                : null;
            $reference = 'OAUTH-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $this->control->failEmergencyOAuthRefreshAndBlock(
                $actor,
                $this->failureClass($error),
                $reference,
                $status
            );
            error_log('ERP_EMERGENCY_OAUTH_REFRESH_FAILED reference=' . $reference
                . ' account_id=' . $accountId . ' class=' . $this->failureClass($error));
            throw new RuntimeException(
                'La renovación OAuth falló. Mercado Libre y la automatización permanecen bloqueados. Referencia: '
                . $reference . '.'
            );
        }
    }

    /** @return array<string,mixed> */
    private function preflight(int $accountId): array
    {
        if (!$this->control->automationStopped()) {
            throw new RuntimeException('Primero detenga la automatización.');
        }
        if (!$this->control->apiStopped()) {
            throw new RuntimeException('Mercado Libre debe permanecer bloqueado durante la renovación OAuth.');
        }
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            throw new RuntimeException('Las escrituras remotas deben permanecer deshabilitadas.');
        }
        if (!OAuthService::isConfigured()) {
            throw new RuntimeException('La configuración OAuth de Mercado Libre está incompleta.');
        }
        $state = $this->control->status()['oauth_refresh'] ?? null;
        if (is_array($state)
            && in_array((string) ($state['state'] ?? ''), ['reserved', 'in_flight', 'response_known'], true)
            && (int) ($state['expires_at'] ?? 0) >= time()) {
            throw new RuntimeException('Ya existe una renovación OAuth de emergencia en curso.');
        }
        $account = ($this->accountLoader)($accountId);
        if (!is_array($account)
            || !in_array((string) ($account['status'] ?? ''), ['conectado', 'connected'], true)
            || trim((string) ($account['meli_user_id'] ?? '')) === ''
            || trim((string) ($account['refresh_token_encrypted'] ?? '')) === '') {
            throw new RuntimeException('La cuenta no está conectada o no tiene refresh token disponible.');
        }
        if (!$this->tokenNeedsRefresh((string) ($account['expires_at'] ?? ''))) {
            throw new RuntimeException('El token de esta cuenta todavía está vigente y no requiere renovación.');
        }
        ($this->protectionCheck)($accountId);
        return $account;
    }

    /** @return array<string,mixed>|null */
    private function loadAccount(int $accountId): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT a.id,a.company_id,a.account_name,a.nickname,a.meli_user_id,a.status,
                    t.refresh_token_encrypted,t.expires_at,t.refresh_version
             FROM meli_accounts a
             INNER JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE a.id=? LIMIT 1"
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $row['nickname'] = trim((string) ($row['nickname'] ?? ''))
            ?: trim((string) ($row['account_name'] ?? 'Cuenta Mercado Libre'));
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function loadTokenState(int $accountId): ?array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT expires_at,refresh_version FROM meli_tokens WHERE meli_account_id=? LIMIT 1'
        );
        $stmt->execute([$accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function assertProtectionsReadableAndClear(int $accountId): void
    {
        $pdo = Database::connection();
        $pause = $pdo->prepare(
            "SELECT COUNT(*) FROM api_manual_pauses
             WHERE status='active' AND (paused_until IS NULL OR paused_until>UTC_TIMESTAMP())
               AND (scope='app' OR (scope='account' AND meli_account_id=?))"
        );
        $pause->execute([$accountId]);
        if ((int) $pause->fetchColumn() > 0) {
            throw new RuntimeException('La cuenta tiene una pausa manual activa.');
        }
        $circuit = $pdo->prepare(
            "SELECT COUNT(*) FROM api_circuit_breakers
             WHERE status='open' AND (blocked_until IS NULL OR blocked_until>UTC_TIMESTAMP())
               AND (meli_account_id IS NULL OR meli_account_id=?)
               AND (endpoint_path='*' OR endpoint_path='/oauth/token')"
        );
        $circuit->execute([$accountId]);
        if ((int) $circuit->fetchColumn() > 0) {
            throw new RuntimeException('La renovación OAuth está protegida por un circuito activo.');
        }
    }

    private function futureExpiry(string $expiresAt): bool
    {
        try {
            return (new DateTimeImmutable($expiresAt, new DateTimeZone('UTC')))->getTimestamp() > time() + 30;
        } catch (Throwable) {
            return false;
        }
    }

    private function tokenNeedsRefresh(string $expiresAt): bool
    {
        $skew = max(30, min(600, (new AppSettingsService())->int('oauth.token_expiry_skew_seconds', 120)));
        try {
            return (new DateTimeImmutable($expiresAt, new DateTimeZone('UTC')))->getTimestamp() <= time() + $skew;
        } catch (Throwable) {
            return true;
        }
    }

    private function failureClass(Throwable $error): string
    {
        if ($error instanceof MeliApiException) {
            return match ((int) ($error->httpStatus ?? 0)) {
                401 => 'http_401',
                403 => 'http_403',
                429 => 'http_429',
                default => ((int) ($error->httpStatus ?? 0)) >= 500 ? 'http_5xx' : 'oauth_remote_failure',
            };
        }
        if ($error instanceof ApiManualPauseException) {
            return 'protection_blocked';
        }
        if ($error instanceof RemoteResultUncertainException) {
            return 'remote_result_uncertain';
        }
        return 'local_or_response_failure';
    }
}
