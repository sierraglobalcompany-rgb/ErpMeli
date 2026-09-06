<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use PDO;
use RuntimeException;
use Throwable;

final class OAuthService
{
    public function authorizationUrl(int $companyId, string $accountName): string
    {
        self::assertConfigured();
        $userId = (int) (Auth::id() ?? 0);
        $this->assertAuthorizedCompany($companyId, $userId, Database::connectionFresh());
        $plainState = bin2hex(random_bytes(32));
        $stmt = Database::connection()->prepare('INSERT INTO meli_oauth_states (state_hash, company_id, account_name, created_by, expires_at) VALUES (:state,:company,:name,:user,DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE))');
        $stmt->execute([
            'state' => hash('sha256', $plainState),
            'company' => $companyId,
            'name' => trim($accountName),
            'user' => $userId,
        ]);
        return Env::get('MELI_AUTH_URL', 'https://auth.mercadolibre.com.co/authorization') . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => Env::get('MELI_CLIENT_ID', ''),
            'redirect_uri' => Env::get('MELI_REDIRECT_URI', ''),
            'state' => $plainState,
        ]);
    }

    public function complete(string $code, string $plainState): int
    {
        return ManualPhysicalCallBudget::withinTechnical(
            1,
            fn (): int => $this->completeWithinBudget($code, $plainState)
        );
    }

    private function completeWithinBudget(string $code, string $plainState): int
    {
        self::assertConfigured();
        // No reclamar ni mutar el estado OAuth si el transporte está detenido.
        // Así el callback puede reintentarse cuando termine el mantenimiento.
        (new MeliEmergencyStopService())->assertAllowed();
        $pdo = Database::connectionFresh();
        $stateHash = hash('sha256', $plainState);
        $userId = (int) (Auth::id() ?? 0);
        if ($userId < 1) {
            throw new RuntimeException('La sesión administrativa no está disponible.');
        }
        $processingToken = bin2hex(random_bytes(24));
        $processingHash = hash('sha256', $processingToken);
        $claim = $pdo->prepare(
            'UPDATE meli_oauth_states
             SET processing_token_hash=?,processing_at=UTC_TIMESTAMP(),last_error_message=NULL
             WHERE state_hash=? AND consumed_at IS NULL AND expires_at>UTC_TIMESTAMP()
               AND created_by=?
               AND (processing_at IS NULL OR processing_at<DATE_SUB(UTC_TIMESTAMP(),INTERVAL 5 MINUTE))'
        );
        $claim->execute([$processingHash, $stateHash, $userId]);
        if ($claim->rowCount() !== 1) {
            throw new RuntimeException('Estado OAuth inválido, usado, vencido o actualmente en procesamiento.');
        }

        try {
            $stmt = $pdo->prepare(
                'SELECT * FROM meli_oauth_states
                 WHERE state_hash=? AND processing_token_hash=? AND created_by=? AND consumed_at IS NULL LIMIT 1'
            );
            $stmt->execute([$stateHash, $processingHash, $userId]);
            $state = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$state) {
                throw new RuntimeException('No fue posible reclamar el estado OAuth.');
            }

            $tokenResponse = $this->formPost('/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => Env::get('MELI_CLIENT_ID', ''),
                'client_secret' => Env::get('MELI_CLIENT_SECRET', ''),
                'code' => $code,
                'redirect_uri' => Env::get('MELI_REDIRECT_URI', ''),
            ], [
                'company_id' => (int) ($state['company_id'] ?? 0),
                'oauth_state_id' => (int) ($state['id'] ?? 0),
            ]);
            foreach (['access_token', 'refresh_token', 'user_id'] as $required) {
                if (empty($tokenResponse[$required])) {
                    throw new RuntimeException("Respuesta OAuth incompleta: falta {$required}.");
                }
            }

            $accessEncrypted = Crypto::encrypt((string) $tokenResponse['access_token']);
            $refreshEncrypted = Crypto::encrypt((string) $tokenResponse['refresh_token']);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + max(60, (int) ($tokenResponse['expires_in'] ?? 21600)));
            $pdo = Database::connectionFresh();
            $pdo->beginTransaction();
            $companyId = (int) $state['company_id'];
            $this->assertAuthorizedCompany($companyId, $userId, $pdo, true);
            $meliUserId = (string) $tokenResponse['user_id'];
            $existing = $pdo->prepare(
                'SELECT id,company_id FROM meli_accounts WHERE meli_user_id=:user LIMIT 1 FOR UPDATE'
            );
            $existing->execute(['user' => $meliUserId]);
            $existingAccount = $existing->fetch(PDO::FETCH_ASSOC);
            if (is_array($existingAccount) && (int) $existingAccount['company_id'] !== $companyId) {
                throw new RuntimeException(
                    'Esta cuenta de Mercado Libre ya está vinculada a otra empresa y no puede trasladarse mediante OAuth.'
                );
            }
            if (is_array($existingAccount)) {
                $accountId = (int) $existingAccount['id'];
                $account = $pdo->prepare(
                    "UPDATE meli_accounts
                     SET account_name=:name,status='conectado'
                     WHERE id=:id AND company_id=:company AND meli_user_id=:user"
                );
                $account->execute([
                    'name' => $state['account_name'],
                    'id' => $accountId,
                    'company' => $companyId,
                    'user' => $meliUserId,
                ]);
            } else {
                $account = $pdo->prepare(
                    "INSERT INTO meli_accounts (company_id,account_name,meli_user_id,status)
                     VALUES (:company,:name,:user,'conectado')"
                );
                $account->execute([
                    'company' => $companyId,
                    'name' => $state['account_name'],
                    'user' => $meliUserId,
                ]);
                $accountId = (int) $pdo->lastInsertId();
            }
            $token = $pdo->prepare('INSERT INTO meli_tokens (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at,token_type,scope) VALUES (:account,:access,:refresh,:expires,:type,:scope) ON DUPLICATE KEY UPDATE access_token_encrypted=VALUES(access_token_encrypted), refresh_token_encrypted=VALUES(refresh_token_encrypted), expires_at=VALUES(expires_at), token_type=VALUES(token_type), scope=VALUES(scope), refresh_version=refresh_version+1');
            $token->execute([
                'account' => $accountId,
                'access' => $accessEncrypted,
                'refresh' => $refreshEncrypted,
                'expires' => $expiresAt,
                'type' => $tokenResponse['token_type'] ?? 'Bearer',
                'scope' => $tokenResponse['scope'] ?? null,
            ]);
            $consume = $pdo->prepare(
                'UPDATE meli_oauth_states
                 SET consumed_at=UTC_TIMESTAMP(),processing_token_hash=NULL,processing_at=NULL,last_error_message=NULL
                 WHERE id=? AND processing_token_hash=? AND created_by=?'
            );
            $consume->execute([$state['id'], $processingHash, $userId]);
            if ($consume->rowCount() !== 1) {
                throw new RuntimeException('El estado OAuth perdió su propiedad antes de guardar la cuenta.');
            }
            $pdo->commit();
            // The authorization POST owns the same physical budget as its
            // optional profile read. Never open another budget after commit.
            if (\App\QueueV4Clean\QueueV4CleanCycleBudget::exhausted()) {
                return $accountId;
            }
            try {
                $profile = ApiExecutionMetadataContext::run(
                    ['source'=>'web', 'job_type'=>'oauth', 'company_id'=>$companyId, 'account_id'=>$accountId],
                    static fn (): array => ApiExecutionMetadataContext::withTechnicalOperation(
                        'oauth_profile',
                        static fn (): array => (new MeliApiClient($accountId))->get('/users/me')
                    )
                );
                $pdo->prepare("UPDATE meli_accounts SET nickname=:nickname, site_id=:site, country_id=:country, status='conectado', last_error=NULL WHERE id=:id AND company_id=:company")
                    ->execute(['nickname' => $profile['nickname'] ?? null, 'site' => $profile['site_id'] ?? null, 'country' => $profile['country_id'] ?? null, 'id' => $accountId, 'company' => $companyId]);
            } catch (Throwable $profileError) {
                Logger::write('warning', 'La cuenta quedó conectada, pero su perfil se completará después.', [
                    'account_id' => $accountId,
                    'error' => Logger::redactString($profileError->getMessage()),
                ]);
            }
            return $accountId;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            try {
                Database::connectionFresh()->prepare(
                    'UPDATE meli_oauth_states
                     SET processing_token_hash=NULL,processing_at=NULL,last_error_message=?
                     WHERE state_hash=? AND processing_token_hash=? AND consumed_at IS NULL'
                )->execute([
                    (new ApiHealthSafeMessageService())->present($e->getMessage())['safe_message'],
                    $stateHash,
                    $processingHash,
                ]);
            } catch (Throwable) {
            }
            throw $e;
        }
    }

    public static function isConfigured(): bool
    {
        return trim((string) Env::get('MELI_CLIENT_ID', '')) !== ''
            && trim((string) Env::get('MELI_CLIENT_SECRET', '')) !== ''
            && trim((string) Env::get('MELI_REDIRECT_URI', '')) !== '';
    }

    public static function assertConfigured(): void
    {
        if (!self::isConfigured()) {
            throw new RuntimeException('Configure primero la aplicación de Mercado Libre: Client ID y Client Secret.');
        }
    }

    private function formPost(string $path, array $data, array $metadata = []): array
    {
        // La barrera física debe evaluarse antes incluso de inspeccionar la
        // conexión local. MeliApiClient volverá a comprobarla justo antes del
        // transporte como defensa en profundidad.
        (new MeliEmergencyStopService())->assertAllowed();
        MeliEndpointRegistry::assertOAuthTokenExchange('POST', $path);
        if (Database::connection()->inTransaction()) {
            throw new RuntimeException('El intercambio OAuth no puede ejecutarse dentro de una transacción MySQL.');
        }
        return ApiExecutionMetadataContext::withTechnicalOperation(
            'initial_oauth',
            static fn (): array => (new MeliApiClient(0))->exchangeOAuthToken($data, array_merge($metadata, [
                'job_type' => 'oauth_authorization',
                'source' => 'web',
            ]))
        );
    }

    private function assertAuthorizedCompany(int $companyId, int $userId, PDO $pdo, bool $forUpdate = false): void
    {
        if ($companyId < 1 || $userId < 1) {
            throw new RuntimeException('La empresa solicitada no está autorizada.');
        }
        $statement = $pdo->prepare(
            'SELECT c.id
             FROM companies c
             JOIN user_company_access uca ON uca.company_id=c.id AND uca.user_id=?
             WHERE c.id=? AND c.status=1 AND c.deleted_at IS NULL
             LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $statement->execute([$userId, $companyId]);
        if ((int) $statement->fetchColumn() !== $companyId) {
            throw new RuntimeException('La empresa solicitada no está autorizada.');
        }
    }
}
