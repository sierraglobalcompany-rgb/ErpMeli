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

/** Ejecuta exclusivamente el canario manual GET /users/me. */
final class EmergencyApiCanaryService
{
    public const SOURCE = 'manual_emergency_canary';
    public const METHOD = 'GET';
    public const ENDPOINT = '/users/me';

    private EmergencyControlService $control;
    /** @var Closure(int):MeliApiClient */
    private Closure $clientFactory;

    /** @param null|callable(int):MeliApiClient $clientFactory */
    public function __construct(?EmergencyControlService $control = null, ?callable $clientFactory = null)
    {
        $this->control = $control ?? new EmergencyControlService();
        $this->clientFactory = $clientFactory !== null
            ? Closure::fromCallable($clientFactory)
            : static fn (int $accountId): MeliApiClient => new MeliApiClient($accountId);
    }

    /** @return list<array{id:int,company_id:int,nickname:string,meli_user_id:string}> */
    public function eligibleAccounts(): array
    {
        $stmt = Database::connection()->query(
            "SELECT a.id,a.company_id,a.account_name,a.nickname,a.meli_user_id,
                    CASE WHEN t.access_token_encrypted IS NOT NULL
                               AND LENGTH(TRIM(t.access_token_encrypted))>0 THEN 1 ELSE 0 END token_present,
                    t.expires_at
             FROM meli_accounts a
             INNER JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE a.status IN ('conectado','connected')
               AND a.meli_user_id IS NOT NULL
               AND t.access_token_encrypted IS NOT NULL
             ORDER BY COALESCE(NULLIF(a.nickname,''),a.account_name),a.id"
        );
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!$this->tokenMetadataReady($row)) {
                continue;
            }
            $rows[] = [
                'id' => (int) $row['id'],
                'company_id' => (int) $row['company_id'],
                'nickname' => trim((string) ($row['nickname'] ?? ''))
                    ?: trim((string) ($row['account_name'] ?? 'Cuenta Mercado Libre')),
                'meli_user_id' => trim((string) $row['meli_user_id']),
            ];
        }
        return $rows;
    }

    /** @return array{account_id:int,nickname:string,http_status:int,completed_at:string} */
    public function run(int $accountId, string $actor): array
    {
        return ManualPhysicalCallBudget::withinTechnical(
            1,
            fn (): array => $this->runWithinBudget($accountId, $actor)
        );
    }

    /** @return array{account_id:int,nickname:string,http_status:int,completed_at:string} */
    private function runWithinBudget(int $accountId, string $actor): array
    {
        $account = $this->preflight($accountId);
        $nonce = $this->control->reserveApiCanary($accountId, (string) $account['meli_user_id']);
        $client = ($this->clientFactory)($accountId);
        $failureClass = '';

        try {
            $metadata = [
                'source' => self::SOURCE,
                'job_type' => 'emergency_canary',
                'company_id' => (int) $account['company_id'],
                'meli_account_id' => $accountId,
                'expected_meli_user_id' => (string) $account['meli_user_id'],
                'canary_method' => self::METHOD,
                'canary_endpoint' => self::ENDPOINT,
                'bulk' => false,
            ];
            $response = EmergencyCanaryTransportContext::run(
                $nonce,
                static fn (): array => ApiExecutionMetadataContext::run(
                    $metadata,
                    static fn (): array => ApiExecutionMetadataContext::withTechnicalOperation(
                        'emergency_canary',
                        static fn (): array => $client->get(self::ENDPOINT, [], $metadata)
                    )
                )
            );
            $actualUserId = $this->canonicalIdentifier($response['id'] ?? null);
            if ($actualUserId === '') {
                $failureClass = 'response_schema_invalid';
                throw new RuntimeException('La respuesta de Mercado Libre no incluyó una identidad verificable.');
            }
            if (!hash_equals((string) $account['meli_user_id'], $actualUserId)) {
                $failureClass = 'account_identity_mismatch';
                throw new RuntimeException('La identidad recibida no coincide con la cuenta seleccionada.');
            }
            $responseMeta = $client->lastResponseMetadata();
            $status = max(0, (int) ($responseMeta['status'] ?? 0));
            $this->control->completeApiCanarySuccess($nonce, $accountId, $actualUserId);
            return [
                'account_id' => $accountId,
                'nickname' => (string) $account['nickname'],
                'http_status' => $status,
                'completed_at' => gmdate(DATE_ATOM),
            ];
        } catch (Throwable $error) {
            $canary = $this->control->status()['canary'] ?? null;
            $status = is_array($canary) && isset($canary['http_status']) ? (int) $canary['http_status'] : null;
            $failureClass = $failureClass !== '' ? $failureClass : $this->safeFailureClass($error);
            $reference = 'CANARY-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
            $this->control->failApiCanaryAndBlock($actor, $failureClass, $reference, $status);
            error_log('ERP_EMERGENCY_CANARY_FAILED reference=' . $reference
                . ' account_id=' . $accountId . ' class=' . $failureClass);
            throw new RuntimeException('La prueba canaria falló y Mercado Libre volvió a quedar bloqueado. Referencia: ' . $reference . '.');
        }
    }

    /** @return array{id:int,company_id:int,nickname:string,meli_user_id:string,token_present:int,expires_at:string} */
    private function preflight(int $accountId): array
    {
        if (!$this->control->automationStopped()) {
            throw new RuntimeException('Primero detenga la automatización.');
        }
        if (Env::bool('ML_WRITE_ENABLED', false)) {
            throw new RuntimeException('Las escrituras remotas deben permanecer deshabilitadas.');
        }
        $canary = $this->control->status()['canary'] ?? null;
        if (!is_array($canary)
            || ($canary['state'] ?? '') !== 'ready'
            || (int) ($canary['expires_at'] ?? 0) <= time()
            || (int) ($canary['used_calls'] ?? 0) !== 0) {
            throw new RuntimeException('No existe una prueba canaria válida y disponible.');
        }
        $stmt = Database::connection()->prepare(
            "SELECT a.id,a.company_id,a.account_name,a.nickname,a.meli_user_id,
                    CASE WHEN t.access_token_encrypted IS NOT NULL
                               AND LENGTH(TRIM(t.access_token_encrypted))>0 THEN 1 ELSE 0 END token_present,
                    t.expires_at
             FROM meli_accounts a
             INNER JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE a.id=? AND a.status IN ('conectado','connected')
             LIMIT 1"
        );
        $stmt->execute([$accountId]);
        $account = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account) || trim((string) ($account['meli_user_id'] ?? '')) === '') {
            throw new RuntimeException('La cuenta seleccionada no está conectada o no tiene identidad Mercado Libre.');
        }
        if (!$this->tokenMetadataReady($account)) {
            throw new RuntimeException('La autorización está vencida o próxima a vencer. Renuévela fuera de la prueba canaria.');
        }
        $this->assertProtectionsReadableAndClear($accountId);
        return [
            'id' => (int) $account['id'],
            'company_id' => (int) $account['company_id'],
            'nickname' => trim((string) ($account['nickname'] ?? ''))
                ?: trim((string) ($account['account_name'] ?? 'Cuenta Mercado Libre')),
            'meli_user_id' => trim((string) $account['meli_user_id']),
            'token_present' => (int) $account['token_present'],
            'expires_at' => (string) $account['expires_at'],
        ];
    }

    /** @param array<string,mixed> $row */
    private function tokenMetadataReady(array $row): bool
    {
        if ((int) ($row['token_present'] ?? 0) !== 1) {
            return false;
        }
        $skew = max(30, min(600, (new AppSettingsService())->int('oauth.token_expiry_skew_seconds', 120)));
        try {
            $expiresAt = new DateTimeImmutable((string) ($row['expires_at'] ?? ''), new DateTimeZone('UTC'));
        } catch (Throwable) {
            return false;
        }
        return $expiresAt->getTimestamp() > time() + $skew;
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
               AND (meli_account_id IS NULL OR meli_account_id=?)"
        );
        $circuit->execute([$accountId]);
        if ((int) $circuit->fetchColumn() > 0) {
            throw new RuntimeException('La cuenta tiene una protección de circuito activa.');
        }
    }

    private function canonicalIdentifier(mixed $value): string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return $value;
        }
        return '';
    }

    private function safeFailureClass(Throwable $error): string
    {
        return match (true) {
            $error instanceof OAuthRefreshRequiredException => 'token_refresh_required',
            $error instanceof ApiManualPauseException => 'protection_blocked',
            $error instanceof RemoteResultUncertainException => 'remote_result_uncertain',
            default => 'local_or_remote_failure',
        };
    }
}
