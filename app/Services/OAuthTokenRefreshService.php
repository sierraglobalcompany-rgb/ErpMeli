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

    public function __construct(private readonly int $accountId)
    {
        $this->settings = new AppSettingsService();
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
            if (!$this->expiresSoon($current)) {
                return $current;
            }

            $response = $requestToken([
                'grant_type' => 'refresh_token',
                'refresh_token' => Crypto::decrypt((string) $current['refresh_token_encrypted']),
            ]);
            if (empty($response['access_token']) || empty($response['refresh_token'])) {
                throw new RuntimeException('La renovación OAuth no entregó ambos tokens.');
            }

            $accessEncrypted = Crypto::encrypt((string) $response['access_token']);
            $refreshEncrypted = Crypto::encrypt((string) $response['refresh_token']);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + max(60, (int) ($response['expires_in'] ?? 21600)));

            $pdo = Database::connectionFresh();
            $pdo->beginTransaction();
            try {
                $update = $pdo->prepare(
                    'UPDATE meli_tokens
                     SET access_token_encrypted=?,refresh_token_encrypted=?,expires_at=?,scope=?,refresh_version=refresh_version+1
                     WHERE meli_account_id=?'
                );
                $update->execute([
                    $accessEncrypted,
                    $refreshEncrypted,
                    $expiresAt,
                    (string) ($response['scope'] ?? $current['scope'] ?? ''),
                    $this->accountId,
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
                throw $error;
            }

            $current['access_token_encrypted'] = $accessEncrypted;
            $current['refresh_token_encrypted'] = $refreshEncrypted;
            $current['expires_at'] = $expiresAt;
            $current['scope'] = (string) ($response['scope'] ?? $current['scope'] ?? '');
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
}
