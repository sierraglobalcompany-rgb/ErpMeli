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
        $pdo = Database::connectionFresh();
        if ($pdo->inTransaction()) {
            throw new RuntimeException('No se puede renovar OAuth dentro de una transacción activa.');
        }

        $lockName = 'erp_meli_oauth_refresh_' . $this->accountId;
        $wait = max(0, min(10, $this->settings->int('oauth.refresh_lock_wait_seconds', 3)));
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, ?)');
        $lockStmt->execute([$lockName, $wait]);
        if ((int) $lockStmt->fetchColumn() !== 1) {
            $fresh = $this->token();
            if ($fresh !== null && !$this->expiresSoon($fresh)) {
                return $fresh;
            }
            throw new OAuthRefreshBusyException('Otra ejecución está renovando el token OAuth; el trabajo se reintentará.');
        }

        try {
            $current = $this->token();
            if ($current === null) {
                throw new RuntimeException('Token OAuth no encontrado.');
            }
            $recovered = $this->recoverDurableRotatedToken($pdo, $current);
            if (is_array($recovered)) {
                return $recovered;
            }
            if (!$this->expiresSoon($current)) {
                return $current;
            }

            // Un POST OAuth puede rotar el refresh token. Queue Core solo cruza
            // el transporte si el escrow por cuenta ya probó persistencia
            // atómica y durable en el filesystem privado.
            $queueRecoveryBeforeTransport = $this->queueRecoveryContext();
            if (is_array($queueRecoveryBeforeTransport)) {
                (new QueueOAuthDurableRecoveryStore())->assertStorageReady();
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
                $pdo->beginTransaction();
                $update = $pdo->prepare(
                    'UPDATE meli_tokens
                     SET access_token_encrypted=?,refresh_token_encrypted=?,expires_at=?,scope=?,token_type=?,refresh_version=?
                     WHERE meli_account_id=? AND refresh_version=?'
                );
                $update->execute([
                    $accessEncrypted,
                    $refreshEncrypted,
                    $expiresAt,
                    $scope,
                    $storedTokenType,
                    $previousRefreshVersion + 1,
                    $this->accountId,
                    $previousRefreshVersion,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('No fue posible guardar el token OAuth renovado.');
                }
                $pdo->prepare("UPDATE meli_accounts SET status='conectado',last_error=NULL WHERE id=?")
                    ->execute([$this->accountId]);
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
                        ? "UPDATE meli_accounts SET status='vencido',last_error=? WHERE id=? AND company_id=?"
                        : "UPDATE meli_accounts SET status='vencido',last_error=? WHERE id=?"
                );
                $parameters = [
                    'La autorización de Mercado Libre venció; conecte nuevamente la cuenta.',
                    $this->accountId,
                ];
                if (is_array($queueScope)) {
                    $parameters[] = $queueScope['company_id'];
                }
                $statement->execute($parameters);
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
    private function token(): ?array
    {
        $stmt = Database::connectionFresh()->prepare(
            'SELECT * FROM meli_tokens WHERE meli_account_id=? LIMIT 1'
        );
        $stmt->execute([$this->accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $token */
    private function expiresSoon(array $token): bool
    {
        $skew = max(30, min(600, $this->settings->int('oauth.token_expiry_skew_seconds', 120)));
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
            && (
                strtolower((string) ($error->response['error'] ?? '')) === 'invalid_grant'
                || str_contains(strtolower($error->getMessage()), 'invalid_grant')
            );
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

        $identity = $pdo->prepare('SELECT meli_user_id FROM meli_accounts WHERE id=? LIMIT 1');
        $identity->execute([$this->accountId]);
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
            $update = $pdo->prepare(
                'UPDATE meli_tokens
                 SET access_token_encrypted=?,refresh_token_encrypted=?,expires_at=?,scope=?,token_type=?,refresh_version=?
                 WHERE meli_account_id=? AND refresh_version=?'
            );
            $update->execute([
                (string) ($recovery['access_token_encrypted'] ?? ''),
                (string) ($recovery['refresh_token_encrypted'] ?? ''),
                (string) ($recovery['expires_at'] ?? ''),
                (string) ($recovery['scope'] ?? ''),
                (string) ($recovery['token_type'] ?? 'Bearer'),
                $targetVersion,
                $this->accountId,
                $previousVersion,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('No fue posible aplicar la recuperación OAuth pendiente.');
            }
            $pdo->prepare("UPDATE meli_accounts SET status='conectado',last_error=NULL WHERE id=?")
                ->execute([$this->accountId]);
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

    /** @return array{company_id:int,expected_meli_user_id:string}|null */
    private function queueRecoveryContext(): ?array
    {
        $metadata = ApiExecutionMetadataContext::current();
        if ((string) ($metadata['source'] ?? '') !== 'queue_core'
            || (string) ($metadata['queue_core_work_type'] ?? '') !== 'oauth_refresh'
            || (int) ($metadata['queue_core_oauth_refresh'] ?? 0) !== 1) {
            return null;
        }
        $accountId = (int) ($metadata['account_id'] ?? 0);
        $transportAccountId = (int) ($metadata['transport_meli_account_id'] ?? $accountId);
        $companyId = (int) ($metadata['company_id'] ?? 0);
        $expected = trim((string) ($metadata['expected_meli_user_id'] ?? ''));
        if ($accountId !== $this->accountId || $transportAccountId !== $this->accountId
            || $companyId < 1 || $expected === '') {
            throw new RuntimeException('Queue OAuth recovery context does not match its transport scope.');
        }
        return ['company_id' => $companyId, 'expected_meli_user_id' => $expected];
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
