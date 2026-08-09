<?php

declare(strict_types=1);

final class ExternalCutoverByteVerifier
{
    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function run(array $command, ?string $cwd = null): array
    {
        $pipes = [];
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $cwd,
            null,
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new RuntimeException('process_unavailable');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [
            'exit' => proc_close($process),
            'stdout' => (string) $stdout,
            'stderr' => (string) $stderr,
        ];
    }

    public static function gitBlobBytes(string $repo, string $commit, string $path): string
    {
        self::assertSafePath($path);
        if (preg_match('/^[a-f0-9]{40,64}$/i', $commit) !== 1) {
            throw new RuntimeException('unsafe_commit');
        }
        $resolved = self::run(['git', '-C', $repo, 'rev-parse', '--verify', $commit . ':' . $path]);
        $oid = strtolower(trim($resolved['stdout']));
        if ($resolved['exit'] !== 0 || preg_match('/^[a-f0-9]{40,64}$/', $oid) !== 1) {
            throw new RuntimeException('git_blob_missing:' . $path);
        }
        $blob = self::run(['git', '-C', $repo, 'cat-file', 'blob', $oid]);
        if ($blob['exit'] !== 0) {
            throw new RuntimeException('git_blob_read_failed:' . $path);
        }

        return $blob['stdout'];
    }

    /**
     * @param array<string,mixed> $authority
     * @return array{failures:list<string>,git_verified:int,package_verified:int,package_optional_absent:int}
     */
    public static function verify(array $authority, string $repo, string $commit, ?string $package): array
    {
        $failures = [];
        $gitVerified = 0;
        $packageVerified = 0;
        $packageOptionalAbsent = 0;
        $entries = array_merge(
            is_array($authority['updater_locked'] ?? null) ? $authority['updater_locked'] : [],
            is_array($authority['migrations'] ?? null) ? $authority['migrations'] : [],
        );
        $zip = null;
        $packageNames = [];
        if ($package !== null) {
            $zip = new ZipArchive();
            if ($zip->open($package, ZipArchive::RDONLY) !== true) {
                throw new RuntimeException('package_unreadable');
            }
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $name = $zip->getNameIndex($index);
                if (is_string($name)) {
                    $packageNames[$name] = ($packageNames[$name] ?? 0) + 1;
                }
            }
        }

        try {
            foreach ($entries as $entry) {
                if (!is_array($entry)) {
                    $failures[] = 'invalid_entry';
                    continue;
                }
                $path = (string) ($entry['path'] ?? '');
                $expectedOid = strtolower((string) ($entry['git_blob_oid'] ?? ''));
                $checkoutSha = strtolower((string) ($entry['sha256'] ?? ''));
                $expectedGitSha = strtolower((string) ($entry['raw_git_sha256'] ?? $checkoutSha));
                // A Git-exact managed package is built from raw object bytes.
                // The legacy sha256 field is retained only as evidence of the
                // line-ending-normalized checkout hash that caused P2-A.
                $expectedPackageSha = $expectedGitSha;
                try {
                    self::assertSafePath($path);
                    $bytes = self::gitBlobBytes($repo, $commit, $path);
                    $actualOid = strtolower(trim(self::run([
                        'git', '-C', $repo, 'rev-parse', '--verify', $commit . ':' . $path,
                    ])['stdout']));
                    if (!hash_equals($expectedOid, $actualOid)) {
                        $failures[] = 'git_blob_oid_mismatch:' . $path;
                    } elseif (!hash_equals($expectedGitSha, hash('sha256', $bytes))) {
                        $failures[] = 'git_blob_sha256_mismatch:' . $path;
                    } else {
                        ++$gitVerified;
                    }
                } catch (Throwable $error) {
                    $failures[] = $error->getMessage();
                }

                if (!$zip instanceof ZipArchive) {
                    continue;
                }
                $required = ($entry['package_required'] ?? true) === true;
                $occurrences = (int) ($packageNames[$path] ?? 0);
                if ($occurrences === 0 && !$required) {
                    ++$packageOptionalAbsent;
                    continue;
                }
                if ($occurrences !== 1) {
                    $failures[] = 'package_path_occurrences:' . $path . ':' . $occurrences;
                    continue;
                }
                $packageBytes = $zip->getFromName($path);
                if (!is_string($packageBytes) || !hash_equals($expectedPackageSha, hash('sha256', $packageBytes))) {
                    $failures[] = 'package_sha256_mismatch:' . $path;
                    continue;
                }
                ++$packageVerified;
            }
        } finally {
            if ($zip instanceof ZipArchive) {
                $zip->close();
            }
        }

        return [
            'failures' => $failures,
            'git_verified' => $gitVerified,
            'package_verified' => $packageVerified,
            'package_optional_absent' => $packageOptionalAbsent,
        ];
    }

    private static function assertSafePath(string $path): void
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || str_contains($path, '\\')
            || str_starts_with($path, '/')
            || preg_match('~(^|/)\.\.(/|$)~', $path) === 1
            || preg_match('~^[A-Za-z]:~', $path) === 1
        ) {
            throw new RuntimeException('unsafe_path:' . $path);
        }
    }
}
