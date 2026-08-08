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
            throw new RuntimeException('Otra ejecución está renovando el token OAuth; el trabajo se reintentará.');
        }

        try {
            $current = $this->token();
            if ($current === null) {
                throw new RuntimeException('Token OAuth no encontrado.');
            }
            $recovered = $this->recoverEmergencyRotatedToken($pdo, $current);
            if (is_array($recovered)) {
                return $recovered;
            }
            if (!$this->expiresSoon($current)) {
                return $current;
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
            $expectedMeliUserId = $this->manualEmergencyExpectedMeliUserId();
            $recoveryStaged = false;
            $recoveryStageFailed = false;
            if ($expectedMeliUserId !== null) {
                try {
                    $this->emergencyControl->stageEmergencyOAuthTokenRecovery(
                        $this->accountId,
                        $expectedMeliUserId,
                        $previousRefreshVersion,
                        [
                            'access_token_encrypted' => $accessEncrypted,
                            'refresh_token_encrypted' => $refreshEncrypted,
                            'expires_at' => $expiresAt,
                            'scope' => $scope,
                            'token_type' => $storedTokenType,
                        ]
                    );
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
                $this->emergencyControl->clearEmergencyOAuthTokenRecovery(
                    $this->accountId,
                    $previousRefreshVersion + 1
                );
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
                Database::connectionFresh()->prepare(
                    "UPDATE meli_accounts SET status='vencido',last_error=? WHERE id=?"
                )->execute(['La autorización de Mercado Libre venció; conecte nuevamente la cuenta.', $this->accountId]);
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
    private function recoverEmergencyRotatedToken(PDO $pdo, array $current): ?array
    {
        $expectedMeliUserId = $this->manualEmergencyExpectedMeliUserId();
        if ($expectedMeliUserId === null) {
            return null;
        }
        $recovery = $this->emergencyControl->emergencyOAuthTokenRecovery(
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
            $this->emergencyControl->clearEmergencyOAuthTokenRecovery($this->accountId, $targetVersion);
            $current['emergency_recovery_applied'] = true;
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

        $this->emergencyControl->clearEmergencyOAuthTokenRecovery($this->accountId, $targetVersion);
        $current['access_token_encrypted'] = (string) $recovery['access_token_encrypted'];
        $current['refresh_token_encrypted'] = (string) $recovery['refresh_token_encrypted'];
        $current['expires_at'] = (string) $recovery['expires_at'];
        $current['scope'] = (string) ($recovery['scope'] ?? '');
        $current['token_type'] = (string) ($recovery['token_type'] ?? 'Bearer');
        $current['refresh_version'] = $targetVersion;
        $current['emergency_recovery_applied'] = true;
        return $current;
    }
}
