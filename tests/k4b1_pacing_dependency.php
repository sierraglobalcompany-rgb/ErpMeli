<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';

// Structural dependency gate, paired with real registry and transport/DB parity.
// Reintroducing the alternate reservation into send() must fail this gate.
$method = new ReflectionMethod(App\Services\MeliApiClient::class, 'send');
$lines = file($method->getFileName());
$body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
$tokens = token_get_all("<?php\n" . $body);
$code = '';
foreach ($tokens as $token) {
    if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) continue;
    $code .= is_array($token) ? $token[1] : $token;
}
k1b_assert(!preg_match('/new(?:\\\\?App\\\\Services\\\\)?ApiPacingService\(\)\)->reserve\(/', $code),
    'UNREACHABLE_PACING_RESERVE_FALLBACK_STILL_PRESENT');
k1b_assert(class_exists(App\Services\ApiPacingService::class), 'pacing_class_preserved');
$report = new ReflectionMethod(App\Services\AutomationCapacityService::class, 'report');
$reportLines = file($report->getFileName());
$reportBody = implode('', array_slice($reportLines, $report->getStartLine()-1, $report->getEndLine()-$report->getStartLine()+1));
k1b_assert(str_contains($reportBody, '(new ApiPacingService())->explain()'), 'pacing_diagnostics_preserved');
echo "K4B1_DEPENDENCY_GREEN\n";
