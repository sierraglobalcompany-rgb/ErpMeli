<?php
declare(strict_types=1);

// Explicit evidence allowlist. Never archives caches, private state or raw source trees.
require_once __DIR__ . '/capacity_artifact.php';

function cap2QaEvidenceNameAllowed(string $name): bool
{
    if (in_array($name, ['MATRIX.md', 'progress.md'], true)) {
        return true;
    }
    if (preg_match('/\.(log|md|diff|png|json|txt)$/iD', $name) !== 1) {
        return false;
    }
    return preg_match('/^(?:baseline-|task[0-9]+-|review-|cap2|capacity|k1d|browser|performance|integrity|control|red-|green-)[A-Za-z0-9._-]*$/iD', $name) === 1;
}

function cap2ReviewEvidenceNameAllowed(string $name): bool
{
    if (preg_match('/\.(md|diff|log|png)$/iD', $name) !== 1) {
        return false;
    }
    if (in_array($name, ['browser-prep-report.md', 'browser-step-prep-report.md', 'package-prep-report.md', 'browser-prep-review.md', 'package-prep-review.md', 'progress.md'], true)) {
        return true;
    }
    return preg_match('/^(?:task-[1-7]-(?:brief|report|review|rereview[0-9]+)\.md|task-[1-7]-fix[0-9]+-review\.md|review-[A-Za-z0-9.]+\.diff)$/iD', $name) === 1;
}

function cap2NestedQaEvidenceAllowed(string $relative): bool
{
    if ($relative === 'package-prep/green-verification.log'
        || preg_match('#^package-prep/round[1-5]-green-verification\.log$#D', $relative) === 1) {
        return true;
    }
    return preg_match('#^browser-config/(?:automatic|manual)-(?:desktop|mobile)\.png$#D', $relative) === 1
        || in_array($relative, [
            'browser-config/red-browser.log',
            'browser-config/green-browser.log',
            'browser-config/final-browser.log',
            'browser-config/review-rerun-browser.log',
            'browser-config/php-server.log',
            'browser-step/setup.log',
            'browser-step/final-browser.log',
            'browser-step/php-server.log',
            'browser-step/wire.jsonl',
            'browser-step/normal-checkpoint-desktop.png',
            'browser-step/protected-429-mobile.png',
            'browser-step/post-checkpoint-failure-desktop.png',
            'browser-step/continuation-result-mobile.png',
        ], true);
}

/**
 * Calls uses a curated flat directory: evidence.json = {"files":["RESULTS.md", ...]}.
 * The caller must review those public reports for sensitive content before staging.
 * Filename checks exclude private state; they are not a content redaction engine.
 */
function callsCuratedEvidenceFiles(string $qa): array
{
    $manifestPath = $qa . '/evidence.json';
    if (!is_file($manifestPath) || is_link($manifestPath)) {
        throw new RuntimeException('calls_evidence_manifest_required');
    }
    $manifestBytes = (string) file_get_contents($manifestPath);
    $manifest = json_decode($manifestBytes, true, 16, JSON_THROW_ON_ERROR);
    $names = $manifest['files'] ?? null;
    if (!is_array($manifest) || array_keys($manifest) !== ['files'] || !is_array($names)
        || !array_is_list($names) || $names === [] || count($names) > 500) {
        throw new RuntimeException('calls_evidence_manifest_invalid');
    }
    $files = ['qa/evidence.json' => $manifestBytes];
    foreach ($names as $name) {
        if (!is_string($name) || strlen($name) > 100 || $name === 'evidence.json'
            || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*\.(?:md|log|txt|diff|png|json)$/iD', $name) !== 1
            || preg_match('/(?:^|[._-])(?:secret|secrets|private|session|sessions|sess|token|tokens|password|credentials|cookie|cookies|database|db|cache|caches|fixture|meta|wire)(?:[._-]|$)/iD', $name) === 1) {
            throw new RuntimeException('calls_evidence_path_denied');
        }
        if (isset($files['qa/' . $name])) {
            throw new RuntimeException('calls_evidence_manifest_invalid');
        }
        $path = $qa . '/' . $name;
        if (!is_file($path) || is_link($path)) {
            throw new RuntimeException('calls_evidence_file_missing');
        }
        $files['qa/' . $name] = (string) file_get_contents($path);
    }
    return $files;
}

/**
 * @return array{qa_zip:string,qa_sha256:string,entry_count:int,entries:list<string>}
 */
function cap2BuildEvidenceZip(string $workspace, string $qa, string $artifact, string $date, string $family = 'cap2'): array
{
    if (!in_array($family, ['cap2', 'calls'], true)) {
        throw new RuntimeException('invalid_package_family');
    }
    $workspace = rtrim(str_replace('\\', '/', $workspace), '/');
    $qa = rtrim(str_replace('\\', '/', $qa), '/');
    $artifact = rtrim(str_replace('\\', '/', $artifact), '/');
    if (preg_match('/^[0-9]{8}$/D', $date) !== 1) {
        throw new RuntimeException('date_must_be_yyyymmdd');
    }
    $targetPath = $artifact . '/target.json';
    if (!is_file($targetPath) || !is_dir($qa)) {
        throw new RuntimeException('artifact_and_qa_required');
    }
    $target = json_decode((string) file_get_contents($targetPath), true, 64, JSON_THROW_ON_ERROR);
    $head = (string) ($target['head'] ?? '');
    if (preg_match('/^[a-f0-9]{40}$/D', $head) !== 1) {
        throw new RuntimeException('target_head_invalid');
    }

    $files = [];
    $planName = $family === 'calls' ? '2026-09-06-calls.md' : '2026-09-05-cap2.md';
    foreach (['CONTROL.txt', 'README.md', 'target.json', 'SHA256SUMS.txt', 'integrity.json', 'rollback.zip', 'verificar.ps1'] as $name) {
        $path = $artifact . '/' . $name;
        if (!is_file($path)) {
            throw new RuntimeException('missing_artifact_evidence:' . $name);
        }
        $files['artifact/' . $name] = (string) file_get_contents($path);
    }

    $tracked = explode("\0", cap2Git($workspace, ['ls-tree', '-r', '--name-only', '-z', $head])['stdout']);
    foreach ($tracked as $path) {
        if ($path === '') {
            continue;
        }
        $include = preg_match('#^tests/cap2_[A-Za-z0-9._-]+\.(?:php|js)$#D', $path) === 1
            || ($family === 'calls' && (preg_match('#^tests/(?:calls|capacity)_[A-Za-z0-9._-]+\.(?:php|js|ps1)$#D', $path) === 1
                || in_array($path, ['tests/k1b_bootstrap.php', 'tests/K1dSafeTestDatabase.php'], true)))
            || in_array($path, [
                'tests/capacity_artifact.php',
                'tests/capacity_handoff.php',
                'tests/capacity_evidence.php',
            ], true);
        if ($include) {
            $files[$path] = cap2GitBlob($workspace, $head, $path);
        } elseif ($path === 'docs/superpowers/plans/' . $planName) {
            $files['plan/' . $planName] = cap2GitBlob($workspace, $head, $path);
        }
    }

    if ($family === 'calls') {
        $files += callsCuratedEvidenceFiles($qa);
    } else {
        $reviewRoot = $workspace . '/.superpowers/sdd/2026-09-05-cap2';
        if (is_dir($reviewRoot)) {
            foreach (new DirectoryIterator($reviewRoot) as $entry) {
                if (!$entry->isFile() || !cap2ReviewEvidenceNameAllowed($entry->getFilename())) {
                    continue;
                }
                $files['review/' . $entry->getFilename()] = (string) file_get_contents($entry->getPathname());
            }
        }
        foreach (new DirectoryIterator($qa) as $entry) {
            if (!$entry->isFile() || !cap2QaEvidenceNameAllowed($entry->getFilename())) {
                continue;
            }
            $files['qa/' . $entry->getFilename()] = (string) file_get_contents($entry->getPathname());
        }
        foreach (['browser-config', 'browser-step', 'package-prep'] as $subdir) {
            $directory = $qa . '/' . $subdir;
            if (!is_dir($directory)) {
                continue;
            }
            foreach (new DirectoryIterator($directory) as $entry) {
                if (!$entry->isFile()) {
                    continue;
                }
                $relative = $subdir . '/' . $entry->getFilename();
                if (cap2NestedQaEvidenceAllowed($relative)) {
                    $files['qa/' . $relative] = (string) file_get_contents($entry->getPathname());
                }
            }
        }
    }
    if (!isset($files['plan/' . $planName])) {
        throw new RuntimeException($family . '_plan_missing_from_target');
    }

    ksort($files, SORT_STRING);
    $hashInventory = [];
    foreach ($files as $path => $bytes) {
        cap2AssertSafePackagePath($path);
        $hashInventory[$path] = hash('sha256', $bytes);
    }
    $files['EVIDENCE_HASHES.json'] = cap2Json($hashInventory);
    $expected = [];
    foreach ($files as $path => $bytes) {
        $expected[$path] = hash('sha256', $bytes);
    }

    $qaZip = $artifact . '/meli-' . $family . '-qa-' . $date . '.zip';
    if (is_file($qaZip)) {
        throw new RuntimeException('qa_zip_exists');
    }
    $zip = new ZipArchive();
    if ($zip->open($qaZip, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
        throw new RuntimeException('qa_zip_create_failed');
    }
    foreach ($files as $path => $bytes) {
        if (!$zip->addFromString($path, $bytes)) {
            throw new RuntimeException('qa_zip_add:' . $path);
        }
    }
    if (!$zip->close()) {
        throw new RuntimeException('qa_zip_close_failed');
    }
    cap2VerifyZip($qaZip, $expected);

    $qaHash = hash_file('sha256', $qaZip);
    cap2WriteFile($artifact . '/CONTROL.txt', implode("\n", [
        rtrim((string) file_get_contents($artifact . '/CONTROL.txt')),
        'QA_ZIP=' . basename($qaZip),
        'QA_SHA256=' . $qaHash,
        'QA_ZIP_REOPEN_HASHES=PASS',
        'QA_ENTRIES=' . count($files),
        'DEPLOY_APPROVED=NO',
        '',
    ]));
    return [
        'qa_zip' => $qaZip,
        'qa_sha256' => $qaHash,
        'entry_count' => count($files),
        'entries' => array_keys($files),
    ];
}

function cap2EvidenceMain(array $argv): int
{
    $workspace = str_replace('\\', '/', $argv[1] ?? '');
    $qa = str_replace('\\', '/', $argv[2] ?? '');
    $artifact = str_replace('\\', '/', $argv[3] ?? '');
    $date = $argv[4] ?? gmdate('Ymd');
    foreach ([$workspace, $qa, $artifact] as $path) {
        if (preg_match('#^[A-Za-z]:/#D', $path) !== 1) {
            throw new RuntimeException('usage: capacity_evidence.php <workspace> <qa> <artifact> [yyyymmdd] [cap2|calls]');
        }
    }
    $result = cap2BuildEvidenceZip($workspace, $qa, $artifact, $date, $argv[5] ?? 'cap2');
    echo 'QA_ZIP=' . $result['qa_zip'] . "\n";
    echo 'QA_SHA256=' . $result['qa_sha256'] . "\n";
    echo 'QA_ZIP_REOPEN_HASHES=PASS' . "\n";
    echo 'QA_ENTRIES=' . $result['entry_count'] . "\n";
    echo 'DEPLOY_APPROVED=NO' . "\n";
    return 0;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    exit(cap2EvidenceMain($argv));
}
