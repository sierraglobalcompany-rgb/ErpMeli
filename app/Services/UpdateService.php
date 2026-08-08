<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\AppPaths;
use App\Core\Database;
use App\Core\Env;
use RuntimeException;
use Throwable;

final class UpdateService
{
    public function runPendingMigrations(?int $maxNewMigrations = null): array
    {
        $from = (new AppVersionService())->installedVersion();
        try {
            $migrator = new Migrator(
                Database::connectionFresh(),
                dirname(__DIR__, 2) . '/database/migrations'
            );
            $results = $migrator->run($maxNewMigrations);
            if ($migrator->pendingCount() === 0) {
                (new CacheInvalidationService())->invalidate('migrations_completed', AppVersionService::fileVersion());
                $integrity = (new ReleaseIntegrityService())->inspect(true);
                if (version_compare(AppVersionService::fileVersion(), '2.11.6', '>=') && !$integrity['ok']) {
                    throw new RuntimeException(
                        'Las migraciones terminaron, pero la instalación contiene componentes mezclados. '
                        . 'Revise Configuración → Cron.'
                    );
                }
                (new AppVersionService())->registerCurrent('Migraciones ejecutadas desde actualizador.');
            }
            $this->log($from, AppVersionService::fileVersion(), 'migrate', 'success', 'Migraciones revisadas desde UI.');
            return $results;
          } catch (Throwable $e) {
              $this->log(
                  $from,
                  AppVersionService::fileVersion(),
                  'migrate',
                  'error',
                  MigrationTraceService::safeMessage($e) ?? 'Error de migración no identificado.'
              );
              throw $e;
          }
    }

    public function clearCache(): string
    {
        $deleted = (new CacheInvalidationService())->invalidate('manual', AppVersionService::fileVersion());
        $this->log((new AppVersionService())->installedVersion(), AppVersionService::fileVersion(), 'clear_cache', 'success', "Archivos eliminados: {$deleted}");
        return "Caché limpiada. Archivos eliminados: {$deleted}.";
    }

    public function handleProtectedLink(?string $key): string
    {
        $settings = new AppSettingsService();
        if (!$settings->bool('update.enabled', false)) {
            $this->rememberLinkAttempt('blocked');
            throw new RuntimeException('Actualización por link desactivada.');
        }
        if (Env::get('APP_KEY', '') === '') {
            $this->rememberLinkAttempt('blocked_missing_app_key');
            throw new RuntimeException('APP_KEY faltante.');
        }
        $hash = $settings->get('update.key_hash');
        $expires = $settings->get('update.key_expires_at');
        if (!$key || !$hash || !password_verify($key, $hash) || ($expires && strtotime($expires) < time())) {
            $this->rememberLinkAttempt('blocked_invalid_key');
            throw new RuntimeException('Llave inválida o vencida.');
        }
        $this->runPendingMigrations();
        $this->rememberLinkAttempt('success');
        return 'Migraciones ejecutadas.';
    }

    private function log(?string $from, ?string $to, string $action, string $status, string $message): void
    {
        try {
            $stmt = Database::connection()->prepare('INSERT INTO update_logs (version_from,version_to,action,status,message,executed_by,ip_hash,user_agent_hash) VALUES (:from_version,:to_version,:action,:status,:message,:user,:ip,:ua)');
            $stmt->execute([
                'from_version' => $from,
                'to_version' => $to,
                'action' => $action,
                'status' => $status,
                'message' => mb_substr($message, 0, 800),
                'user' => Auth::id(),
                'ip' => hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli')),
                'ua' => hash('sha256', (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'cli')),
            ]);
        } catch (Throwable) {
            Logger::write('warning', 'No se pudo registrar update_logs.', ['action' => $action, 'status' => $status]);
        }
    }

    private function rememberLinkAttempt(string $result): void
    {
        $settings = new AppSettingsService();
        $settings->set('update.last_attempt_at', date('Y-m-d H:i:s'), 'update');
        $settings->set('update.last_attempt_result', $result, 'update');
    }
}
