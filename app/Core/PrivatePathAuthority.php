<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Central fail-closed authority for private filesystem locations. */
final class PrivatePathAuthority
{
    private const SERVED_COMPONENTS = ['public_html', 'htdocs', 'httpdocs', 'wwwroot'];

    /** @param list<string> $trustedServedRoots */
    public function __construct(
        private readonly ?string $releaseRoot = null,
        private readonly ?string $installationRoot = null,
        private readonly ?string $documentRoot = null,
        private readonly array $trustedServedRoots = [],
    ) {
    }

    /** @return array{source:string,id:string,path:string} */
    public function privateRootIdentity(): array
    {
        $configured = trim((string) Env::get('ERP_PRIVATE_PATH', ''));
        if ($configured !== '') {
            $source = 'ERP_PRIVATE_PATH';
        } else {
            $home = trim((string) (getenv('HOME') ?: ($_SERVER['HOME'] ?? '')));
            $source = $home !== '' && is_dir($home) && is_writable($home)
                ? 'HOME'
                : 'INSTALLATION_FALLBACK';
        }
        $path = $this->normalizeAbsolute(AppPaths::privateRoot());
        $canonical = realpath($path);
        $identity = $this->comparePath($canonical !== false ? $canonical : $path);
        return ['source' => $source, 'id' => hash('sha256', $identity), 'path' => $path];
    }

    /** @return array<string,mixed> */
    public function assess(string $target): array
    {
        $path = $this->normalizeAbsolute($target);
        $this->assertNoLexicalSymlink($path);
        [$canonicalTarget, $nearestExisting] = $this->canonicalTarget($path);

        $release = $this->canonicalOrLexical($this->releaseRoot ?? AppPaths::releaseRoot());
        $installation = $this->canonicalOrLexical($this->installationRoot ?? AppPaths::installationRoot());
        $derived = [];
        foreach ([$release, $installation] as $root) {
            $served = $this->deepestServedAncestor($root);
            if ($served !== null) {
                $derived[$this->comparePath($served)] = $served;
            }
        }
        foreach ($this->trustedServedRoots as $root) {
            $trusted = $this->canonicalOrLexical($root);
            $derived[$this->comparePath($trusted)] = $trusted;
        }

        $documentState = 'ABSENT_OR_EMPTY';
        $document = trim($this->documentRoot ?? (string) ($_SERVER['DOCUMENT_ROOT'] ?? ''));
        if ($document !== '') {
            try {
                $document = $this->canonicalOrLexical($document);
                if ($derived === []) {
                    $documentState = $this->deepestServedAncestor($document) !== null ? 'CREDIBLE' : 'UNRELATED_IGNORED';
                    if ($documentState === 'CREDIBLE') {
                        $derived[$this->comparePath($document)] = $document;
                    }
                } else {
                    $credible = false;
                    $overbroad = false;
                    foreach ($derived as $served) {
                        $credible = $credible || $this->sameOrDescendant($document, $served);
                        $overbroad = $overbroad || $this->sameOrDescendant($served, $document);
                    }
                    $documentState = $credible ? 'CREDIBLE' : ($overbroad ? 'OVERBROAD_IGNORED' : 'UNRELATED_IGNORED');
                }
            } catch (RuntimeException) {
                $documentState = 'INVALID';
            }
        }

        if ($derived === []) {
            throw new RuntimeException('No authoritative served boundary is available.');
        }
        foreach ([$release, $installation, ...array_values($derived)] as $served) {
            if ($this->sameOrDescendant($canonicalTarget, $served)) {
                throw new RuntimeException('Private path must remain outside every served application tree.');
            }
        }
        if ($this->containsServedComponent($path) || $this->containsServedComponent($canonicalTarget)) {
            throw new RuntimeException('Private path contains a conventional served-tree component.');
        }

        return [
            'absolute' => true,
            'symlink_free' => true,
            'derived_public_root_available' => true,
            'document_root_state' => $documentState,
            'inside_served_tree' => false,
            'canonical_path' => $canonicalTarget,
            'nearest_existing_ancestor' => $nearestExisting,
        ];
    }

    /** @return array{path:string,device:int,inode:int,type:int} */
    public function ensurePrivateDirectory(string $directory, int $mode = 0700): array
    {
        $assessment = $this->assess($directory);
        $target = (string) $assessment['canonical_path'];
        $existing = (string) $assessment['nearest_existing_ancestor'];
        $relative = ltrim(substr($target, strlen(rtrim($existing, '/'))), '/');
        $cursor = rtrim($existing, '/');
        if ($relative !== '') {
            foreach (explode('/', $relative) as $component) {
                $cursor .= '/' . $component;
                if (!is_dir($cursor) && !@mkdir($cursor, $mode, false) && !is_dir($cursor)) {
                    throw new RuntimeException('Private directory could not be created safely.');
                }
                if (DIRECTORY_SEPARATOR === '/' && !@chmod($cursor, $mode)) {
                    throw new RuntimeException('Private directory permissions could not be restricted.');
                }
                $this->assess($cursor);
            }
        }
        return $this->directoryIdentity($directory);
    }

    /** @return array{path:string,device:int,inode:int,type:int} */
    public function directoryIdentity(string $directory): array
    {
        $this->assess($directory);
        if (!is_dir($directory) || is_link($directory)) {
            throw new RuntimeException('Private path is not a trusted directory.');
        }
        $stat = @lstat($directory);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0040000) {
            throw new RuntimeException('Private directory identity is unavailable.');
        }
        return [
            'path' => $this->canonicalOrLexical($directory),
            'device' => (int) $stat['dev'],
            'inode' => (int) $stat['ino'],
            'type' => (int) ($stat['mode'] & 0170000),
        ];
    }

    /** @param array{path:string,device:int,inode:int,type:int} $identity */
    public function assertDirectoryIdentity(string $directory, array $identity): void
    {
        $current = $this->directoryIdentity($directory);
        if (!$this->samePath($current['path'], $identity['path'])
            || $current['device'] !== $identity['device'] || $current['inode'] !== $identity['inode']
            || $current['type'] !== $identity['type']) {
            throw new RuntimeException('Private directory identity changed.');
        }
    }

    /** @param array{path:string,device:int,inode:int,type:int} $directoryIdentity
     *  @return array{device:int,inode:int,type:int}
     */
    public function regularFileIdentity(string $path, array $directoryIdentity): array
    {
        $this->assertDirectoryIdentity(dirname($path), $directoryIdentity);
        if (is_link($path)) {
            throw new RuntimeException('OAuth recovery file cannot be a symbolic link.');
        }
        $stat = @lstat($path);
        if (!is_array($stat) || ($stat['mode'] & 0170000) !== 0100000) {
            throw new RuntimeException('OAuth recovery path is not a regular file.');
        }
        return ['device' => (int) $stat['dev'], 'inode' => (int) $stat['ino'], 'type' => 0100000];
    }

    /** @param array{device:int,inode:int,type:int} $identity */
    public function assertRegularFileIdentity(string $path, array $directoryIdentity, array $identity): void
    {
        $current = $this->regularFileIdentity($path, $directoryIdentity);
        if ($current !== $identity) {
            throw new RuntimeException('OAuth recovery file identity changed.');
        }
    }

    private function normalizeAbsolute(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));
        if ($path === '' || (!str_starts_with($path, '/') && preg_match('#^[A-Za-z]:/#', $path) !== 1)) {
            throw new RuntimeException('Private path must be absolute.');
        }
        $prefix = str_starts_with($path, '/') ? '/' : substr($path, 0, 3);
        $tail = str_starts_with($path, '/') ? substr($path, 1) : substr($path, 3);
        $parts = $tail === '' ? [] : explode('/', trim($tail, '/'));
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('Private path contains an unsafe component.');
            }
        }
        return $prefix . implode('/', $parts);
    }

    private function assertNoLexicalSymlink(string $path): void
    {
        $prefix = str_starts_with($path, '/') ? '/' : substr($path, 0, 3);
        $tail = str_starts_with($path, '/') ? substr($path, 1) : substr($path, 3);
        $cursor = rtrim($prefix, '/');
        foreach ($tail === '' ? [] : explode('/', $tail) as $part) {
            $cursor = ($cursor === '' ? '/' : $cursor . '/') . $part;
            if (is_link($cursor)) {
                throw new RuntimeException('Private path cannot traverse a symbolic link.');
            }
        }
    }

    /** @return array{string,string} */
    private function canonicalTarget(string $path): array
    {
        $cursor = $path;
        $missing = [];
        while (!file_exists($cursor) && !is_link($cursor)) {
            $parent = str_replace('\\', '/', dirname($cursor));
            if ($parent === $cursor) {
                throw new RuntimeException('Private path has no existing authoritative ancestor.');
            }
            array_unshift($missing, basename($cursor));
            $cursor = $parent;
        }
        $real = realpath($cursor);
        if ($real === false) {
            throw new RuntimeException('Private path ancestor cannot be canonicalized.');
        }
        $real = rtrim(str_replace('\\', '/', $real), '/');
        $target = $real . ($missing === [] ? '' : '/' . implode('/', $missing));
        return [$target, $real === '' ? '/' : $real];
    }

    private function canonicalOrLexical(string $path): string
    {
        $normalized = $this->normalizeAbsolute($path);
        $real = realpath($normalized);
        return rtrim(str_replace('\\', '/', $real !== false ? $real : $normalized), '/') ?: '/';
    }

    private function deepestServedAncestor(string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);
        $drive = preg_match('#^[A-Za-z]:/#', $normalized) === 1 ? substr($normalized, 0, 3) : '/';
        $tail = $drive === '/' ? substr($normalized, 1) : substr($normalized, 3);
        $parts = $tail === '' ? [] : explode('/', trim($tail, '/'));
        $match = null;
        foreach ($parts as $index => $part) {
            if (in_array($this->fold($part), self::SERVED_COMPONENTS, true)) {
                $match = $drive . implode('/', array_slice($parts, 0, $index + 1));
            }
        }
        return $match === null ? null : rtrim($match, '/');
    }

    private function containsServedComponent(string $path): bool
    {
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if (in_array($this->fold($part), self::SERVED_COMPONENTS, true)) {
                return true;
            }
        }
        return false;
    }

    private function sameOrDescendant(string $candidate, string $root): bool
    {
        $candidate = rtrim($this->comparePath($candidate), '/');
        $root = rtrim($this->comparePath($root), '/');
        return $candidate === $root || str_starts_with($candidate . '/', $root . '/');
    }

    private function samePath(string $left, string $right): bool
    {
        return rtrim($this->comparePath($left), '/') === rtrim($this->comparePath($right), '/');
    }

    private function comparePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }

    private function fold(string $component): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? strtolower($component) : $component;
    }
}
