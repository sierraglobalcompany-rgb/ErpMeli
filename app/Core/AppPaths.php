<?php

declare(strict_types=1);

namespace App\Core;

final class AppPaths
{
    public static function releaseRoot(): string
    {
        return defined('ERP_RELEASE_ROOT')
            ? rtrim((string) constant('ERP_RELEASE_ROOT'), '/\\')
            : dirname(__DIR__, 2);
    }

    public static function installationRoot(): string
    {
        return defined('ERP_INSTALLATION_ROOT')
            ? rtrim((string) constant('ERP_INSTALLATION_ROOT'), '/\\')
            : self::releaseRoot();
    }

    public static function sharedRoot(): string
    {
        if (defined('ERP_SHARED_ROOT')) {
            return rtrim((string) constant('ERP_SHARED_ROOT'), '/\\');
        }

        $managed = self::installationRoot() . '/shared';
        return is_file($managed . '/current-release.json')
            ? $managed
            : self::installationRoot();
    }

    public static function storage(string $relative = ''): string
    {
        $path = self::sharedRoot() . '/storage';
        return $relative === '' ? $path : $path . '/' . ltrim(str_replace('\\', '/', $relative), '/');
    }

    public static function configFile(): string
    {
        foreach ([self::sharedRoot() . '/config.env', self::releaseRoot() . '/config.env', self::releaseRoot() . '/.env'] as $file) {
            if (is_file($file)) {
                return $file;
            }
        }
        return self::sharedRoot() . '/config.env';
    }

    public static function releases(): string
    {
        return self::installationRoot() . '/releases';
    }

    public static function updateInbox(): string
    {
        return self::managed()
            ? self::installationRoot() . '/shared/update-inbox'
            : self::storage('update-inbox');
    }

    public static function updateStaging(): string
    {
        return self::managed()
            ? self::installationRoot() . '/shared/update-staging'
            : self::storage('update-staging');
    }

    public static function updateBackups(): string
    {
        return self::managed()
            ? self::installationRoot() . '/shared/update-backups'
            : self::storage('update-backups');
    }

    public static function backups(): string
    {
        return self::privateRoot() . '/backups';
    }

    /**
     * Ubicación usada por versiones anteriores. Solo se conserva para una
     * migración explícita; las copias nuevas nunca deben escribirse dentro de
     * la raíz servida por la web.
     */
    public static function legacyBackups(): string
    {
        return self::managed()
            ? self::installationRoot() . '/shared/backups'
            : self::storage('backups');
    }

    public static function coldArchives(): string
    {
        return self::privateRoot() . '/cold-archives';
    }

    public static function remotePayloads(): string
    {
        return self::privateRoot() . '/remote-payloads';
    }

    public static function privateRoot(): string
    {
        $configured = trim((string) Env::get('ERP_PRIVATE_PATH', ''));
        if ($configured !== '') {
            return rtrim($configured, '/\\');
        }

        $home = trim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')));
        if ($home !== '' && is_dir($home) && is_writable($home)) {
            return rtrim($home, '/\\') . '/.erp-meli-private';
        }

        // La instalación habitual vive en public_html/erp-meli. Dos niveles
        // arriba queda fuera del document root. Nunca guardar claves en storage.
        return dirname(self::installationRoot(), 2) . '/.erp-meli-private';
    }

    public static function backupKeys(): string
    {
        return self::privateRoot() . '/backup-keys';
    }

    public static function restoreSecrets(): string
    {
        return self::privateRoot() . '/restore-secrets';
    }

    public static function managed(): bool
    {
        return defined('ERP_SHARED_ROOT')
            || is_file(self::installationRoot() . '/shared/current-release.json');
    }
}
