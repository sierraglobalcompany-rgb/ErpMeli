<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;

final class OAuthTokenRefreshService
{
    private AppSettingsService $settings;
    private EmergencyControlService $emergencyControl;

    public function __construct(private readonly int $accountId)
    {
        $this->settings = new AppSettingsService();
        $this->emergencyControl = new EmergencyControlService();
    }

    /**
     * @param callable(array<string,mixed>):array<string,mixed> $requestToken
     * @return array<string,mixed>
     */
    public function refresh(callable $requestToken): array
    {
        \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
            \App\QueueV4Clean\QueueV4CleanOAuthStageContext::OAUTH_REFRESH_SERVICE
        );
        $pdo = Database::connectionFresh();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('No se puede renovar OAuth dentro de una transacción activa.');
        }

        $lockName = 'erp_meli_oauth_refresh_' . $this->accountId;
        $wait = max(0, min(10, $this->settings->int('oauth.refresh_lock_wait_seconds', 3)));
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $lockStmt->execute([$lockName, $wait]);
        if ((int) $lockStmt->fetchColumn() !== 1) {
            $busyScope = $this->queueRecoveryContext();
            $fresh = $this->token(is_array($busyScope) ? $busyScope['company_id'] : null);
            if ($fresh !== null && !$this->expiresSoon($fresh)) {
                return $fresh;
            }
            throw new OAuthRefreshBusyException('Otra ejecución está renovando el token OAuth; el trabajo se reintentará.');
        }

        try {
            $queueScope = $this->queueRecoveryContext();
            $current = $this->token(is_array($queueScope) ? $queueScope['company_id'] : null);
            if ($current === null) {
                throw new RuntimeException('Token OAuth no encontrado.');
            }
            $recovered = $this->recoverDurableRotatedToken($pdo, $current);
            if (is_array($recovered)) {
                return $recovered;
            }
            if (is_array($queueScope)) {
                $currentVersion = (int) ($current['refresh_version'] ?? -1);
                if ($currentVersion > $queueScope['expected_refresh_version']) {
                    // Otro worker ya ganó el CAS de esta cuenta. El trabajo
                    // lógico termina sin un segundo POST OAuth.
                    return $current;
                }
                if ($currentVersion !== $queueScope['expected_refresh_version']) {
                    throw new RuntimeException('La generación OAuth cambió antes del transporte.');
                }
                $this->assertQueueAccountIdentity($pdo, $queueScope);
            }
            if (!$this->expiresSoon($current)) {
                return $current;
            }

            // Un POST OAuth puede rotar el refresh token. Queue Core solo cruza
            // el transporte si el escrow por cuenta ya probó persistencia
            // atómica y durable en el filesystem privado.
            $queueRecoveryBeforeTransport = $this->queueRecoveryContext();
            if (is_array($queueRecoveryBeforeTransport)) {
                \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
                    \App\QueueV4Clean\QueueV4CleanOAuthStageContext::TOKEN_ESCROW
                );
                (new QueueOAuthDurableRecoveryStore())->assertStorageReady();
                // The filesystem preflight may take time. Re-read both seller
                // identity and refresh generation at the last local boundary
                // before the callback can issue POST /oauth/token.
                $this->assertQueueAccountIdentity($pdo, $queueRecoveryBeforeTransport);
            }

            $response = $requestToken([
                'grant_type' => 'refresh_token',
                'refresh_token' => Crypto::decrypt((string) $current['refresh_token_encrypted']),
            ]);
            $accessToken = trim((string) ($response['access_token'] ?? ''));
            $refreshToken = trim((string) ($response['refresh_token'] ?? ''));
            $expiresIn = filter_var($response['expires_in'] ?? null, FILTER_VALIDATE_INT);
            $tokenType = trim((string) ($response['token_type'] ?? ''));
            if ($accessToken === '' || $refreshToken === '') {
                throw new RuntimeException('La renovación OAuth no entregó ambos tokens.');
            }
            if ($expiresIn === false || $expiresIn < 60) {
                throw new RuntimeException('La renovación OAuth no entregó una vigencia válida.');
            }
            if ($tokenType !== '' && strcasecmp($tokenType, 'Bearer') !== 0) {
                throw new RuntimeException('La renovación OAuth entregó un tipo de token inesperado.');
            }

            $accessEncrypted = Crypto::encrypt($accessToken);
            $refreshEncrypted = Crypto::encrypt($refreshToken);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + $expiresIn);
            $scope = (string) ($response['scope'] ?? $current['scope'] ?? '');
            $storedTokenType = $tokenType !== '' ? $tokenType : (string) ($current['token_type'] ?? 'Bearer');
            $previousRefreshVersion = (int) ($current['refresh_version'] ?? 0);
            $manualExpectedMeliUserId = $this->manualEmergencyExpectedMeliUserId();
            $queueRecovery = $this->queueRecoveryContext();
            $expectedMeliUserId = $manualExpectedMeliUserId
                ?? (is_array($queueRecovery) ? $queueRecovery['expected_meli_user_id'] : null);
            $recoveryStaged = false;
            $recoveryStageFailed = false;
            if ($expectedMeliUserId !== null) {
                try {
                    $tokenForRecovery = [
                        'access_token_encrypted' => $accessEncrypted,
                        'refresh_token_encrypted' => $refreshEncrypted,
                        'expires_at' => $expiresAt,
                        'scope' => $scope,
                        'token_type' => $storedTokenType,
                    ];
                    if ($manualExpectedMeliUserId !== null) {
                        $this->emergencyControl->stageEmergencyOAuthTokenRecovery(
                            $this->accountId,
                            $expectedMeliUserId,
                            $previousRefreshVersion,
                            $tokenForRecovery
                        );
                    } elseif (is_array($queueRecovery)) {
                        \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
                            \App\QueueV4Clean\QueueV4CleanOAuthStageContext::TOKEN_ESCROW
                        );
                        (new QueueOAuthDurableRecoveryStore())->stage(
                            $queueRecovery['company_id'],
                            $this->accountId,
                            $expectedMeliUserId,
                            $previousRefreshVersion,
                            $tokenForRecovery
                        );
                    }
                    $recoveryStaged = true;
                } catch (Throwable) {
                    $recoveryStageFailed = true;
                    // El body sigue en memoria: se intenta el commit MariaDB
                    // inmediatamente. Nunca se descarta una respuesta OAuth
                    // conocida solo porque el escrow local no pudo escribirse.
                }
            }

            $pdo = Database::connectionFresh();
            try {
                \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
                    \App\QueueV4Clean\QueueV4CleanOAuthStageContext::TOKEN_DB_CAS
                );
                $pdo->beginTransaction();
                $update = $pdo->prepare(is_array($queueRecovery)
                    ? "UPDATE meli_tokens t
                       INNER JOIN meli_accounts a ON a.id=t.meli_account_id
                       SET t.access_token_encrypted=?,t.refresh_token_encrypted=?,t.expires_at=?,t.scope=?,t.token_type=?,t.refresh_version=?
                       WHERE t.meli_account_id=? AND t.refresh_version=?
                         AND a.company_id=? AND a.meli_user_id=?
                         AND a.status IN ('conectado','connected')"
                    : 'UPDATE meli_tokens
                       SET access_token_encrypted=?,refresh_token_encrypted=?,expires_at=?,scope=?,token_type=?,refresh_version=?
                       WHERE meli_account_id=? AND refresh_version=?');
                $updateParameters = [
                    $accessEncrypted,
                    $refreshEncrypted,
                    $expiresAt,
                    $scope,
                    $storedTokenType,
                    $previousRefreshVersion + 1,
                    $this->accountId,
                    $previousRefreshVersion,
                ];
                if(is_array($queueRecovery)){
                    $updateParameters[]=$queueRecovery['company_id'];
                    $updateParameters[]=$queueRecovery['expected_meli_user_id'];
                }
                $update->execute($updateParameters);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('No fue posible guardar el token OAuth renovado.');
                }
                $accountUpdate = $pdo->prepare(is_array($queueRecovery)
                    ? "UPDATE meli_accounts SET status='conectado',last_error=NULL WHERE id=? AND company_id=?"
                    : "UPDATE meli_accounts SET status='conectado',last_error=NULL WHERE id=?");
                $accountParameters = [$this->accountId];
                if (is_array($queueRecovery)) {
                    $accountParameters[] = $queueRecovery['company_id'];
                }
                $accountUpdate->execute($accountParameters);
                if($accountUpdate->rowCount()===0 && is_array($queueRecovery)){
                    $verified=$pdo->prepare("SELECT COUNT(*) FROM meli_accounts WHERE id=? AND company_id=? AND meli_user_id=? AND status IN ('conectado','connected') AND last_error IS NULL");
                    $verified->execute([$this->accountId,$queueRecovery['company_id'],$queueRecovery['expected_meli_user_id']]);
                    if((int)$verified->fetchColumn()!==1)throw new RuntimeException('OAuth account fence changed before commit.');
                }
                $pdo->commit();
            } catch (Throwable $error) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($expectedMeliUserId !== null && $recoveryStageFailed) {
                    throw new RotatedCredentialRecoveryUnavailableException(
                        'La credencial OAuth rotada no pudo conservarse en el escrow ni en MariaDB.',
                        0,
                        $error
                    );
                }
                throw $error;
            }

            if ($recoveryStaged) {
                if ($manualExpectedMeliUserId !== null) {
                    $this->emergencyControl->clearEmergencyOAuthTokenRecovery(
                        $this->accountId,
                        $previousRefreshVersion + 1
                    );
                } else {
                    (new QueueOAuthDurableRecoveryStore())->clear(
                        $this->accountId,
                        $previousRefreshVersion + 1
                    );
                }
            }

            $current['access_token_encrypted'] = $accessEncrypted;
            $current['refresh_token_encrypted'] = $refreshEncrypted;
            $current['expires_at'] = $expiresAt;
            $current['scope'] = $scope;
            $current['token_type'] = $storedTokenType;
            $current['refresh_version'] = $previousRefreshVersion + 1;
            return $current;
        } catch (Throwable $error) {
            if ($this->isInvalidGrant($error)) {
                $queueScope = $this->queueRecoveryContext();
                $statement = Database::connectionFresh()->prepare(
                    is_array($queueScope)
                        ? "UPDATE meli_accounts SET status='vencido',last_error=? WHERE id=? AND company_id=? AND meli_user_id=?"
                        : "UPDATE meli_accounts SET status='vencido',last_error=? WHERE id=?"
                );
                $parameters = [
                    'La autorización de Mercado Libre venció; conecte nuevamente la cuenta.',
                    $this->accountId,
                ];
                if (is_array($queueScope)) {
                    $parameters[] = $queueScope['company_id'];
                    $parameters[] = $queueScope['expected_meli_user_id'];
                }
                $statement->execute($parameters);
                if($statement->rowCount()===0 && is_array($queueScope)){
                    $verify=Database::connectionFresh()->prepare("SELECT COUNT(*) FROM meli_accounts WHERE id=? AND company_id=? AND meli_user_id=? AND status='vencido'");
                    $verify->execute([$this->accountId,$queueScope['company_id'],$queueScope['expected_meli_user_id']]);
                    if((int)$verify->fetchColumn()!==1){
                        throw new RuntimeException('OAuth invalid_grant identity fence changed.');
                    }
                }
            }
            throw $error;
        } finally {
            try {
                Database::connectionFresh()->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
            } catch (Throwable) {
            }
        }
    }

    /** @return array<string,mixed>|null */
    private function token(?int $companyId = null): ?array
    {
        $stmt = Database::connectionFresh()->prepare($companyId !== null
            ? 'SELECT t.* FROM meli_tokens t INNER JOIN meli_accounts a ON a.id=t.meli_account_id
               WHERE t.meli_account_id=? AND a.company_id=? LIMIT 1'
            : 'SELECT * FROM meli_tokens WHERE meli_account_id=? LIMIT 1');
        $parameters = [$this->accountId];
        if ($companyId !== null) {
            $parameters[] = $companyId;
        }
        $stmt->execute($parameters);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $token */
    private function expiresSoon(array $token): bool
    {
        $source = (string) (ApiExecutionMetadataContext::current()['source'] ?? '');
        $skew = $source === MeliTransportSourcePolicy::QUEUE_V4_OAUTH
            ? max(300, min(7200, $this->settings->int('oauth.auto_refresh_lead_seconds', 3600)))
            : max(30, min(600, $this->settings->int('oauth.token_expiry_skew_seconds', 120)));
        $rawExpiry = trim((string) ($token['expires_at'] ?? ''));
        if ($rawExpiry === '') {
            return true;
        }
        try {
            $expiry = new DateTimeImmutable($rawExpiry, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return true;
        }
        return $expiry->getTimestamp() <= time() + $skew;
    }

    private function isInvalidGrant(Throwable $error): bool
    {
        return $error instanceof MeliApiException
            && strtolower((string) ($error->response['error'] ?? '')) === 'invalid_grant';
    }

    private function manualEmergencyExpectedMeliUserId(): ?string
    {
        $metadata = ApiExecutionMetadataContext::current();
        if ((string) ($metadata['source'] ?? '') !== EmergencyOAuthRefreshService::SOURCE) {
            return null;
        }
        if ((int) ($metadata['meli_account_id'] ?? 0) !== $this->accountId
            || (int) ($metadata['transport_meli_account_id'] ?? $this->accountId) !== $this->accountId) {
            throw new RuntimeException('La recuperación OAuth no coincide con la cuenta del transporte.');
        }
        $expected = trim((string) ($metadata['expected_meli_user_id'] ?? ''));
        if ($expected === '') {
            throw new RuntimeException('La recuperación OAuth no tiene identidad Mercado Libre esperada.');
        }
        return $expected;
    }

    /**
     * @param array<string,mixed> $current
     * @return array<string,mixed>|null
     */
    private function recoverDurableRotatedToken(PDO $pdo, array $current): ?array
    {
        $manualExpectedMeliUserId = $this->manualEmergencyExpectedMeliUserId();
        $queueRecovery = $this->queueRecoveryContext();
        $expectedMeliUserId = $manualExpectedMeliUserId
            ?? (is_array($queueRecovery) ? $queueRecovery['expected_meli_user_id'] : null);
        if ($expectedMeliUserId === null) {
            return null;
        }
        $recovery = $manualExpectedMeliUserId !== null
            ? $this->emergencyControl->emergencyOAuthTokenRecovery(
                $this->accountId,
                $expectedMeliUserId
            )
            : (new QueueOAuthDurableRecoveryStore())->load(
                (int) $queueRecovery['company_id'],
                $this->accountId,
                $expectedMeliUserId
            );
        if (!is_array($recovery)) {
            return null;
        }

        $identity = $pdo->prepare($queueRecovery !== null
            ? 'SELECT meli_user_id FROM meli_accounts WHERE id=? AND company_id=? LIMIT 1'
            : 'SELECT meli_user_id FROM meli_accounts WHERE id=? LIMIT 1');
        $identityParameters = [$this->accountId];
        if ($queueRecovery !== null) {
            $identityParameters[] = $queueRecovery['company_id'];
        }
        $identity->execute($identityParameters);
        if (!hash_equals($expectedMeliUserId, trim((string) $identity->fetchColumn()))) {
            throw new RuntimeException('La recuperación OAuth no coincide con la identidad actual de la cuenta.');
        }

        $previousVersion = (int) ($recovery['previous_refresh_version'] ?? -1);
        $targetVersion = (int) ($recovery['target_refresh_version'] ?? -1);
        $currentVersion = (int) ($current['refresh_version'] ?? -1);
        if ($previousVersion < 0 || $targetVersion !== $previousVersion + 1) {
            throw new RuntimeException('La recuperación OAuth pendiente tiene una generación inválida.');
        }
        if ($currentVersion >= $targetVersion) {
            $this->clearDurableRecovery($manualExpectedMeliUserId !== null, $targetVersion);
            $current['emergency_recovery_applied'] = $manualExpectedMeliUserId !== null;
            $current['queue_recovery_applied'] = $manualExpectedMeliUserId === null;
            return $current;
        }
        if ($currentVersion !== $previousVersion) {
            throw new RuntimeException('La generación OAuth cambió antes de aplicar la recuperación pendiente.');
        }

        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare($queueRecovery !== null
                ? "UPDATE meli_tokens t
                   INNER JOIN meli_accounts a ON a.id=t.meli_account_id
                   SET t.access_token_encrypted=?,t.refresh_token_encrypted=?,t.expires_at=?,t.scope=?,t.token_type=?,t.refresh_version=?
                   WHERE t.meli_account_id=? AND t.refresh_version=?
                     AND a.company_id=? AND a.meli_user_id=?
                     AND a.status IN ('conectado','connected')"
                : 'UPDATE meli_tokens
                   SET access_token_encrypted=?,refresh_token_encrypted=?,expires_at=?,scope=?,token_type=?,refresh_version=?
                   WHERE meli_account_id=? AND refresh_version=?');
            $updateParameters = [
                (string) ($recovery['access_token_encrypted'] ?? ''),
                (string) ($recovery['refresh_token_encrypted'] ?? ''),
                (string) ($recovery['expires_at'] ?? ''),
                (string) ($recovery['scope'] ?? ''),
                (string) ($recovery['token_type'] ?? 'Bearer'),
                $targetVersion,
                $this->accountId,
                $previousVersion,
            ];
            if($queueRecovery!==null){
                $updateParameters[]=$queueRecovery['company_id'];
                $updateParameters[]=$queueRecovery['expected_meli_user_id'];
            }
            $update->execute($updateParameters);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('No fue posible aplicar la recuperación OAuth pendiente.');
            }
            $accountUpdate = $pdo->prepare($queueRecovery !== null
                ? "UPDATE meli_accounts SET status='conectado',last_error=NULL WHERE id=? AND company_id=?"
                : "UPDATE meli_accounts SET status='conectado',last_error=NULL WHERE id=?");
            $accountParameters = [$this->accountId];
            if ($queueRecovery !== null) {
                $accountParameters[] = $queueRecovery['company_id'];
            }
            $accountUpdate->execute($accountParameters);
            if($accountUpdate->rowCount()===0 && $queueRecovery!==null){
                $verified=$pdo->prepare("SELECT COUNT(*) FROM meli_accounts WHERE id=? AND company_id=? AND meli_user_id=? AND status IN ('conectado','connected') AND last_error IS NULL");
                $verified->execute([$this->accountId,$queueRecovery['company_id'],$queueRecovery['expected_meli_user_id']]);
                if((int)$verified->fetchColumn()!==1)throw new RuntimeException('OAuth recovery account fence changed before commit.');
            }
            $pdo->commit();
        } catch (Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        $this->clearDurableRecovery($manualExpectedMeliUserId !== null, $targetVersion);
        $current['access_token_encrypted'] = (string) $recovery['access_token_encrypted'];
        $current['refresh_token_encrypted'] = (string) $recovery['refresh_token_encrypted'];
        $current['expires_at'] = (string) $recovery['expires_at'];
        $current['scope'] = (string) ($recovery['scope'] ?? '');
        $current['token_type'] = (string) ($recovery['token_type'] ?? 'Bearer');
        $current['refresh_version'] = $targetVersion;
        $current['emergency_recovery_applied'] = $manualExpectedMeliUserId !== null;
        $current['queue_recovery_applied'] = $manualExpectedMeliUserId === null;
        return $current;
    }

    /** @return array{company_id:int,expected_meli_user_id:string,expected_refresh_version:int,oauth_operation_id?:int,oauth_lease_owner?:string,oauth_lease_generation?:int}|null */
    private function queueRecoveryContext(): ?array
    {
        $metadata = ApiExecutionMetadataContext::current();
        $source = (string) ($metadata['source'] ?? '');
        $queueCore = $source === 'queue_core'
            && (string) ($metadata['queue_core_work_type'] ?? '') === 'oauth_refresh'
            && (int) ($metadata['queue_core_oauth_refresh'] ?? 0) === 1;
        $current = $source === MeliTransportSourcePolicy::QUEUE_V4_OAUTH;
        if (!$queueCore && !$current) {
            return null;
        }
        $accountId = (int) ($metadata['account_id'] ?? 0);
        $transportAccountId = (int) ($metadata['transport_meli_account_id'] ?? $accountId);
        $companyId = (int) ($metadata['company_id'] ?? 0);
        $expected = trim((string) ($metadata['expected_meli_user_id'] ?? ''));
        $expectedVersion = (int) ($metadata['expected_refresh_version'] ?? -1);
        if ($accountId !== $this->accountId || $transportAccountId !== $this->accountId
            || $companyId < 1 || $expected === '' || $expectedVersion < 0) {
            throw new RuntimeException('Queue OAuth recovery context does not match its transport scope.');
        }
        $scope = [
            'company_id' => $companyId,
            'expected_meli_user_id' => $expected,
            'expected_refresh_version' => $expectedVersion,
        ];
        if ($current) {
            $operationId = (int) ($metadata['oauth_operation_id'] ?? 0);
            $owner = (string) ($metadata['oauth_lease_owner'] ?? '');
            $generation = (int) ($metadata['oauth_lease_generation'] ?? 0);
            if ($operationId < 1 || $owner === '' || $generation < 1) {
                throw new RuntimeException('Queue V4 OAuth operation authority is incomplete.');
            }
            $scope += [
                'oauth_operation_id' => $operationId,
                'oauth_lease_owner' => $owner,
                'oauth_lease_generation' => $generation,
            ];
        }
        return $scope;
    }

    /**
     * Revalidates the tenant and seller identity after winning the per-account
     * advisory lock and immediately before any OAuth POST can be attempted.
     *
     * @param array{company_id:int,expected_meli_user_id:string,expected_refresh_version:int} $scope
     */
    private function assertQueueAccountIdentity(PDO $pdo, array $scope): void
    {
        $statement = $pdo->prepare(
            'SELECT a.meli_user_id,a.status,COALESCE(t.refresh_version,0) AS refresh_version
             FROM meli_accounts a INNER JOIN meli_tokens t ON t.meli_account_id=a.id
             WHERE a.id=? AND a.company_id=? LIMIT 1'
        );
        $statement->execute([$this->accountId, $scope['company_id']]);
        $account = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($account)
            || !hash_equals($scope['expected_meli_user_id'], trim((string) ($account['meli_user_id'] ?? '')))
            || !in_array((string) ($account['status'] ?? ''), ['conectado', 'connected'], true)
            || (int) ($account['refresh_version'] ?? -1) !== $scope['expected_refresh_version']) {
            throw new RuntimeException('La identidad OAuth de la cuenta cambió antes del transporte.');
        }
    }

    private function clearDurableRecovery(bool $manual, int $targetVersion): void
    {
        if ($manual) {
            $this->emergencyControl->clearEmergencyOAuthTokenRecovery($this->accountId, $targetVersion);
            return;
        }
        (new QueueOAuthDurableRecoveryStore())->clear($this->accountId, $targetVersion);
    }
}
