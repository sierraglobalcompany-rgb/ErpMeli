<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

/** Pure, no-I/O classifier for production-to-target runtime identity. */
final class RuntimeDriftClassifier
{
    public const EXPECTED_UPGRADE_DELTA = 'EXPECTED_UPGRADE_DELTA';
    public const ALREADY_TARGET = 'ALREADY_TARGET';
    public const UNEXPECTED_DRIFT = 'UNEXPECTED_DRIFT';

    /**
     * @param ?string $explicitAuthority A stable classification supplied by an
     *        exact path/hash authority (for example PROTECTED_STATE or a
     *        quarantine-manifest classification).
     */
    public static function classify(
        ?string $productionSha256,
        ?string $knownInstalledSha256,
        ?string $targetSha256,
        ?string $explicitAuthority = null,
    ): string {
        foreach ([$productionSha256, $knownInstalledSha256, $targetSha256] as $hash) {
            if ($hash !== null && preg_match('/^[a-f0-9]{64}$/D', strtolower($hash)) !== 1) {
                throw new InvalidArgumentException('Runtime identity must be a SHA-256 hash or null.');
            }
        }
        $productionSha256 = $productionSha256 !== null ? strtolower($productionSha256) : null;
        $knownInstalledSha256 = $knownInstalledSha256 !== null ? strtolower($knownInstalledSha256) : null;
        $targetSha256 = $targetSha256 !== null ? strtolower($targetSha256) : null;

        if ($productionSha256 !== null && $targetSha256 !== null
            && hash_equals($targetSha256, $productionSha256)
        ) {
            return self::ALREADY_TARGET;
        }
        if ($productionSha256 === null && $targetSha256 !== null) {
            return self::EXPECTED_UPGRADE_DELTA;
        }
        if ($productionSha256 !== null && $knownInstalledSha256 !== null
            && hash_equals($knownInstalledSha256, $productionSha256)
            && ($targetSha256 === null || !hash_equals($productionSha256, $targetSha256))
        ) {
            return self::EXPECTED_UPGRADE_DELTA;
        }
        if ($explicitAuthority !== null && trim($explicitAuthority) !== '') {
            return trim($explicitAuthority);
        }

        return self::UNEXPECTED_DRIFT;
    }
}
