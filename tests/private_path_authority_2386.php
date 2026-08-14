<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$sandbox = sys_get_temp_dir() . '/erp-private-authority-2386-' . bin2hex(random_bytes(5));
$host = $sandbox . '/home/user';
$installation = $host . '/domains/example.com/public_html/erp-meli';
$private = $host . '/.erp-meli-private';
mkdir($installation, 0700, true);
mkdir($private, 0700, true);
putenv('APP_KEY=private-path-authority-2386-local-only');
$_ENV['APP_KEY'] = 'private-path-authority-2386-local-only';
require $root . '/bootstrap.php';

use App\Core\Crypto;
use App\Core\PrivatePathAuthority;
use App\Services\QueueOAuthDurableRecoveryStore;

$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
    $checks++;
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$blocked = static function (callable $callable) use ($assert): void {
    try {
        $callable();
    } catch (RuntimeException) {
        $assert(true, 'blocked');
        return;
    }
    $assert(false, 'unsafe_path_was_accepted');
};
$remove = static function (string $path) use (&$remove): void {
    if (is_link($path) || is_file($path)) { @unlink($path); return; }
    if (!is_dir($path)) { return; }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) { $remove($path . '/' . $entry); }
    @rmdir($path);
};

try {
    $broad = new PrivatePathAuthority($installation, $installation, $host);
    $assessment = $broad->assess($private . '/queue-oauth-recovery');
    $assert($assessment['document_root_state'] === 'OVERBROAD_IGNORED', 'broad_document_root_not_ignored');

    $empty = new PrivatePathAuthority($installation, $installation, '');
    $assert($empty->assess($private)['document_root_state'] === 'ABSENT_OR_EMPTY', 'empty_document_root_not_ignored');
    $missing = new PrivatePathAuthority($installation, $installation, null);
    $originalDocumentRoot = $_SERVER['DOCUMENT_ROOT'] ?? null;
    unset($_SERVER['DOCUMENT_ROOT']);
    $assert($missing->assess($private)['document_root_state'] === 'ABSENT_OR_EMPTY', 'missing_document_root_not_ignored');
    if ($originalDocumentRoot !== null) { $_SERVER['DOCUMENT_ROOT'] = $originalDocumentRoot; }

    $rootDocument = new PrivatePathAuthority($installation, $installation, '/');
    $assert($rootDocument->assess($private)['document_root_state'] === 'OVERBROAD_IGNORED', 'filesystem_root_not_ignored');
    $servedRoot = dirname($installation);
    $credible = new PrivatePathAuthority($installation, $installation, $servedRoot);
    $assert($credible->assess($private)['document_root_state'] === 'CREDIBLE', 'credible_document_root_not_recognized');
    $unrelated = new PrivatePathAuthority($installation, $installation, $sandbox . '/unrelated/httpdocs');
    $assert($unrelated->assess($private)['document_root_state'] === 'UNRELATED_IGNORED', 'unrelated_document_root_not_ignored');
    $invalidDocument = new PrivatePathAuthority($installation, $installation, '../public_html');
    $assert($invalidDocument->assess($private)['document_root_state'] === 'INVALID', 'invalid_document_root_not_rejected');

    $forbidden = $host . '/domains/example.com/public_html/secret/private';
    $blocked(static fn () => $broad->ensurePrivateDirectory($forbidden));
    $assert(!is_dir($forbidden), 'forbidden_directory_was_created_before_validation');
    $blocked(static fn () => $broad->assess($host . '/domains/other.example/public_html/private'));
    $broad->assess($host . '/domains/other.example/public_html_backup/private');
    $assert(true, 'prefix_boundary');
    $blocked(static fn () => $broad->assess($installation));
    $blocked(static fn () => $broad->assess($servedRoot));
    $blocked(static fn () => $broad->assess($installation . '/private'));
    foreach (['../private', './private', 'queue-oauth-recovery'] as $relative) {
        $blocked(static fn () => $broad->assess($relative));
    }
    $noBoundary = new PrivatePathAuthority($sandbox . '/release', $sandbox . '/install', '');
    $blocked(static fn () => $noBoundary->assess($private));

    $identity = $broad->ensurePrivateDirectory($private . '/identity');
    rename($private . '/identity', $private . '/identity-old');
    mkdir($private . '/identity', 0700);
    $blocked(static fn () => $broad->assertDirectoryIdentity($private . '/identity', $identity));

    $symlinkSupported = false;
    $realDirectory = $private . '/real';
    mkdir($realDirectory, 0700);
    $linkDirectory = $private . '/link';
    if (@symlink($realDirectory, $linkDirectory)) {
        $symlinkSupported = true;
        $blocked(static fn () => $broad->assess($linkDirectory . '/recovery'));
    }

    $recovery = $private . '/queue-oauth-recovery';
    $store = new QueueOAuthDurableRecoveryStore($recovery, $broad);
    $store->assertStorageReady();
    $probe = $store->probeStatus();
    $assert($probe === ['created' => true, 'removed' => true], 'ephemeral_probe_not_removed');
    $token = [
        'access_token_encrypted' => Crypto::encrypt('access'),
        'refresh_token_encrypted' => Crypto::encrypt('refresh'),
        'expires_at' => '2026-08-14 08:00:00',
        'scope' => 'read',
        'token_type' => 'Bearer',
    ];
    $store->stage(5, 3, 'seller-3', 22, $token);
    $escrowFile = $recovery . '/account-3.json';
    $blocked(static fn () => $store->stage(5, 3, 'seller-3', 22, $token));
    $assert(glob($recovery . '/account-3.json.tmp-*') === [], 'atomic_temporary_file_was_not_removed');
    if (DIRECTORY_SEPARATOR === '/') {
        $assert((((int) fileperms($escrowFile)) & 0777) === 0600, 'recovery_file_mode_invalid');
    }
    $loaded = $store->load(5, 3, 'seller-3');
    $assert(is_array($loaded) && (int) $loaded['target_refresh_version'] === 23, 'escrow_stage_load_failed');
    $blocked(static fn () => $store->load(4, 3, 'seller-3'));
    $blocked(static fn () => $store->clear(3, 24));
    $store->clear(3, 23);
    $assert($store->load(5, 3, 'seller-3') === null, 'escrow_clear_failed');

    mkdir($recovery . '/account-4.json', 0700);
    $blocked(static fn () => $store->load(5, 4, 'seller-4'));
    rmdir($recovery . '/account-4.json');

    if ($symlinkSupported) {
        $outside = $private . '/outside.json';
        file_put_contents($outside, '{}');
        @symlink($outside, $recovery . '/account-3.json');
        $blocked(static fn () => $store->load(5, 3, 'seller-3'));
        $blocked(static fn () => $store->clear(3, 23));
    }

    if (DIRECTORY_SEPARATOR === '/') {
        $assert((((int) fileperms($recovery)) & 0777) === 0700, 'recovery_directory_mode_invalid');
    }
    $originalCwd = getcwd();
    chdir($host);
    $legacyEmptyRoot = realpath('');
    $legacyWouldRejectPrivate = is_string($legacyEmptyRoot)
        && str_starts_with(str_replace('\\', '/', $private) . '/', str_replace('\\', '/', $legacyEmptyRoot) . '/');
    chdir((string) $originalCwd);
    $assert($legacyWouldRejectPrivate, 'empty_realpath_defect_not_reproduced');

    fwrite(STDOUT, 'PRIVATE_PATH_AUTHORITY_2386=PASS checks=' . $checks
        . ' symlink_supported=' . ($symlinkSupported ? 'yes' : 'no')
        . ' real_meli_http=0 business_writes=0 raw_storage_touched=false' . PHP_EOL);
} finally {
    $remove($sandbox);
}
