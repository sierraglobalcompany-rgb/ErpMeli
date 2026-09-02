<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\AppPaths;
use App\Core\Crypto;
use App\Core\PrivatePathAuthority;
use RuntimeException;
use Throwable;

/** Escrow cifrado por cuenta para una respuesta OAuth conocida aún no persistida. */
final class QueueOAuthDurableRecoveryStore
{
    private PrivatePathAuthority $pathAuthority;
    /** @var array{created:bool,removed:bool} */
    private array $probeStatus = ['created' => false, 'removed' => false];

    public function __construct(private readonly ?string $directory = null, ?PrivatePathAuthority $pathAuthority = null)
    {
        $this->pathAuthority = $pathAuthority ?? new PrivatePathAuthority();
    }

    /** Comprueba durabilidad antes del POST sin leer ni sustituir un escrow pendiente. */
    public function assertStorageReady(): void
    {
        $directory = $this->root();
        $identity = $this->ensureDirectory($directory);
        $probe = $directory . '/.preflight-' . bin2hex(random_bytes(6));
        $this->probeStatus = ['created' => false, 'removed' => false];
        try {
            $this->atomicWrite($probe, ['version' => 1, 'purpose' => 'preflight'], $identity);
            $this->probeStatus['created'] = true;
        } finally {
            if (is_file($probe) && !is_link($probe) && @unlink($probe)) {
                $this->probeStatus['removed'] = true;
                $this->syncDirectory($directory);
            }
        }
        if (!$this->probeStatus['removed']) {
            throw new RuntimeException('Queue OAuth recovery preflight could not be cleaned up.');
        }
    }

    /** @return array{created:bool,removed:bool} */
    public function probeStatus(): array { return $this->probeStatus; }

    /** @return array<string,mixed> */
    public function pathDiagnostics(): array { return $this->pathAuthority->assess($this->root()); }

    /**
     * @param array{access_token_encrypted:string,refresh_token_encrypted:string,expires_at:string,scope:string,token_type:string} $token
     */
    public function stage(
        int $companyId,
        int $accountId,
        string $expectedMeliUserId,
        int $previousRefreshVersion,
        array $token,
    ): void {
        if ($companyId < 1 || $accountId < 1 || trim($expectedMeliUserId) === ''
            || $previousRefreshVersion < 0 || trim($token['access_token_encrypted']) === ''
            || trim($token['refresh_token_encrypted']) === '' || trim($token['expires_at']) === '') {
            throw new RuntimeException('Queue OAuth recovery identity is invalid.');
        }
        $targetVersion = $previousRefreshVersion + 1;
        $payload = [
            'version' => 1,
            'company_id' => $companyId,
            'meli_account_id' => $accountId,
            'expected_meli_user_id' => trim($expectedMeliUserId),
            'previous_refresh_version' => $previousRefreshVersion,
            'target_refresh_version' => $targetVersion,
            'access_token_encrypted' => $token['access_token_encrypted'],
            'refresh_token_encrypted' => $token['refresh_token_encrypted'],
            'expires_at' => $token['expires_at'],
            'scope' => $token['scope'],
            'token_type' => $token['token_type'],
            'created_at' => gmdate(DATE_ATOM),
        ];
        $sealed = Crypto::encrypt(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $identityFence = hash_hmac('sha256', trim($expectedMeliUserId), $sealed);
        $identity = $this->ensureDirectory($this->root());
        $target = $this->path($accountId);
        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('Queue OAuth recovery must be reconciled before another token request.');
        }
        $this->atomicWrite($target, [
            'version' => 1,
            'state' => 'pending_db_persist',
            'company_id' => $companyId,
            'meli_account_id' => $accountId,
            'identity_fence' => $identityFence,
            'previous_refresh_version' => $previousRefreshVersion,
            'target_refresh_version' => $targetVersion,
            'sealed_payload' => $sealed,
        ], $identity);
    }

    /** @return array<string,mixed>|null */
    public function load(int $companyId, int $accountId, string $expectedMeliUserId): ?array
    {
        $path = $this->path($accountId);
        $directoryIdentity = $this->ensureDirectory($this->root());
        if (!file_exists($path) && !is_link($path)) {
            return null;
        }
        $fileIdentity = $this->pathAuthority->regularFileIdentity($path, $directoryIdentity);
        $contents = file_get_contents($path);
        $this->pathAuthority->assertRegularFileIdentity($path, $directoryIdentity, $fileIdentity);
        $document = json_decode((string) $contents, true);
        if (!is_array($document) || ($document['state'] ?? '') !== 'pending_db_persist'
            || (int) ($document['company_id'] ?? 0) !== $companyId
            || (int) ($document['meli_account_id'] ?? 0) !== $accountId
            || !hash_equals(
                (string) ($document['identity_fence'] ?? ''),
                hash_hmac('sha256', trim($expectedMeliUserId), (string) ($document['sealed_payload'] ?? ''))
            )) {
            throw new RuntimeException('Queue OAuth recovery belongs to another scope.');
        }
        try {
            $payload = json_decode(Crypto::decrypt((string) ($document['sealed_payload'] ?? '')), true, 32, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new RuntimeException('Queue OAuth recovery could not be authenticated.');
        }
        if (!is_array($payload)
            || (int) ($payload['company_id'] ?? 0) !== $companyId
            || (int) ($payload['meli_account_id'] ?? 0) !== $accountId
            || !hash_equals((string) ($payload['expected_meli_user_id'] ?? ''), trim($expectedMeliUserId))
            || (int) ($payload['previous_refresh_version'] ?? -1) !== (int) ($document['previous_refresh_version'] ?? -2)
            || (int) ($payload['target_refresh_version'] ?? -1) !== (int) ($document['target_refresh_version'] ?? -2)) {
            throw new RuntimeException('Queue OAuth recovery payload does not match its public fence.');
        }
        return $payload;
    }

    public function clear(int $accountId, int $targetRefreshVersion): void
    {
        $path = $this->path($accountId);
        $directoryIdentity = $this->ensureDirectory($this->root());
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        $fileIdentity = $this->pathAuthority->regularFileIdentity($path, $directoryIdentity);
        $contents = file_get_contents($path);
        $this->pathAuthority->assertRegularFileIdentity($path, $directoryIdentity, $fileIdentity);
        $document = json_decode((string) $contents, true);
        if (!is_array($document)
            || (int) ($document['meli_account_id'] ?? 0) !== $accountId
            || (int) ($document['target_refresh_version'] ?? -1) !== $targetRefreshVersion) {
            throw new RuntimeException('Queue OAuth recovery clear fence was rejected.');
        }
        $this->pathAuthority->assertRegularFileIdentity($path, $directoryIdentity, $fileIdentity);
        if (!@unlink($path)) {
            throw new RuntimeException('Queue OAuth recovery could not be cleared.');
        }
        $this->syncDirectory(dirname($path));
    }

    private function path(int $accountId): string
    {
        return $this->root() . '/account-' . max(0, $accountId) . '.json';
    }

    private function root(): string
    {
        return rtrim($this->directory ?? (AppPaths::privateRoot() . '/queue-oauth-recovery'), '/\\');
    }

    /** @param array<string,mixed> $payload */
    private function atomicWrite(string $path, array $payload, ?array $directoryIdentity = null): void
    {
        $directory = dirname($path);
        $directoryIdentity ??= $this->ensureDirectory($directory);
        $this->pathAuthority->assertDirectoryIdentity($directory, $directoryIdentity);
        if (file_exists($path) || is_link($path)) {
            throw new RuntimeException('Queue OAuth recovery target already exists.');
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(6));
        $handle = @fopen($temporary, 'xb');
        if (!is_resource($handle)) {
            throw new RuntimeException('Queue OAuth recovery temporary file is unavailable.');
        }
        try {
            try {
                $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                    throw new RuntimeException('Queue OAuth recovery could not be flushed.');
                }
                if (function_exists('fsync') && !fsync($handle)) {
                    throw new RuntimeException('Queue OAuth recovery could not be synchronized.');
                }
            } finally {
                fclose($handle);
            }
            if (DIRECTORY_SEPARATOR === '/' && !@chmod($temporary, 0600)) {
                throw new RuntimeException('Queue OAuth recovery temporary permissions could not be restricted.');
            }
            $this->pathAuthority->regularFileIdentity($temporary, $directoryIdentity);
            $this->pathAuthority->assertDirectoryIdentity($directory, $directoryIdentity);
            if (!@rename($temporary, $path)) {
                throw new RuntimeException('Queue OAuth recovery could not be published atomically.');
            }
        } catch (Throwable $error) {
            if (!is_link($temporary)) {
                @unlink($temporary);
            }
            throw $error;
        }
        $this->pathAuthority->regularFileIdentity($path, $directoryIdentity);
        if (DIRECTORY_SEPARATOR === '/' && (!@chmod($path, 0600) || (((int) @fileperms($path)) & 0077) !== 0)) {
            throw new RuntimeException('Queue OAuth recovery file permissions are not private.');
        }
        $this->syncDirectory($directory);
    }

    /** @return array{path:string,device:int,inode:int,type:int} */
    private function ensureDirectory(string $directory): array
    {
        $identity = $this->pathAuthority->ensurePrivateDirectory($directory, 0700);
        if (DIRECTORY_SEPARATOR === '/' && !@chmod($directory, 0700)) {
            throw new RuntimeException('Queue OAuth recovery directory permissions could not be restricted.');
        }
        if (!is_writable($directory)) {
            throw new RuntimeException('Queue OAuth recovery directory is not writable.');
        }
        if (DIRECTORY_SEPARATOR === '/' && ((int) @fileperms($directory) & 0077) !== 0) {
            throw new RuntimeException('Queue OAuth recovery directory permissions are not private.');
        }
        return $identity;
    }

    /** Linux durability fence. Other supported platforms retain atomic rename semantics. */
    private function syncDirectory(string $directory): void
    {
        if (DIRECTORY_SEPARATOR !== '/' || !function_exists('fsync')) {
            return;
        }
        $handle = @fopen($directory, 'r');
        if (!is_resource($handle)) {
            throw new RuntimeException('Queue OAuth recovery directory could not be opened for synchronization.');
        }
        try {
            if (!fsync($handle)) {
                throw new RuntimeException('Queue OAuth recovery directory synchronization failed.');
            }
        } finally {
            fclose($handle);
        }
    }
}
