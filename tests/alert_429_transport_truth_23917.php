<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$summary = (string) file_get_contents($root . '/app/Services/ApiErrorSummaryService.php');
$alerts = (string) file_get_contents($root . '/app/Services/AlertService.php');

$check(str_contains($summary, 'function recentRemoteRateLimits'), 'remote_429_authority_query_missing');
$check(
    str_contains($summary, 'l.reached_remote=1 AND l.http_status=429')
        && str_contains($summary, 'l.company_id IN')
        && str_contains($summary, 'INTERVAL 72 HOUR'),
    'remote_429_query_must_require_known_transport_and_be_bounded'
);
$check(!str_contains($alerts, 'if ((int) $row[\'http_status\'] === 429)'), 'legacy_error_summary_must_not_raise_429_alerts');
$check(
    str_contains($alerts, 'recentRemoteRateLimits(50, $accountIds, $companyIds)')
        && str_contains($alerts, 'Mercado Libre respondió HTTP 429'),
    'alerts_must_use_remote_transport_authority'
);

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "ALERT_429_TRANSPORT_TRUTH_23917=PASS real_meli_http=0\n";
