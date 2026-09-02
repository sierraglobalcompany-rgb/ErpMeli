<?php

declare(strict_types=1);

namespace App\Services;

final class DirectUpdateTransitionPolicy
{
    /**
     * @return array{schema_matches:bool,metadata_only_eligible:bool,downgrade_blocked:bool,reason:string}
     */
    public static function evaluate(
        string $fileVersion,
        string $installedVersion,
        int $pendingCount,
        bool $filesReady,
        bool $minimumMigrationApplied
    ): array {
        $validFile = self::validVersion($fileVersion);
        $validInstalled = self::validVersion($installedVersion);
        $schemaMatches = $validFile && $validInstalled && hash_equals($fileVersion, $installedVersion);
        $downgrade = $validFile && $validInstalled && version_compare($installedVersion, $fileVersion, '>');
        $eligible = $validFile
            && $validInstalled
            && $filesReady
            && $minimumMigrationApplied
            && $pendingCount === 0
            && version_compare($installedVersion, $fileVersion, '<');

        $reason = match (true) {
            !$validFile => 'file_version_invalid',
            !$validInstalled => 'installed_version_invalid',
            $downgrade => 'downgrade_refused',
            $schemaMatches => 'current',
            $pendingCount > 0 => 'migrations_pending',
            !$filesReady => 'files_invalid',
            !$minimumMigrationApplied => 'minimum_migration_missing',
            $eligible => 'metadata_only_required',
            default => 'transition_blocked',
        };

        return [
            'schema_matches' => $schemaMatches,
            'metadata_only_eligible' => $eligible,
            'downgrade_blocked' => $downgrade,
            'reason' => $reason,
        ];
    }

    private static function validVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+$/D', trim($version)) === 1;
    }
}
