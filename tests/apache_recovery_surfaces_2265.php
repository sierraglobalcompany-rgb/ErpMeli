<?php

declare(strict_types=1);

$base = rtrim(
    (string) (getenv('ERP_HTTP_BASE') ?: 'http://localhost/erp-meli-local'),
    '/'
);
$failures = [];

/**
 * @param array<string,string> $headers
 * @return array{status:int,body:string,headers:list<string>,cookies:array<string,string>}
 */
$request = static function (
    string $path,
    string $method = 'GET',
    array $headers = [],
    string $body = '',
    array $cookies = []
) use ($base): array {
    $handle = curl_init($base . $path);
    $responseHeaders = [];
    $requestHeaders = [];
    foreach ($headers as $name => $value) {
        $requestHeaders[] = $name . ': ' . $value;
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => $requestHeaders,
        CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
            $responseHeaders[] = trim($line);
            return strlen($line);
        },
    ]);
    if ($cookies !== []) {
        curl_setopt(
            $handle,
            CURLOPT_COOKIE,
            implode('; ', array_map(
                static fn (string $name, string $value): string => $name . '=' . $value,
                array_keys($cookies),
                array_values($cookies)
            ))
        );
    }
    if ($body !== '') {
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
    }
    $responseBody = curl_exec($handle);
    if (!is_string($responseBody)) {
        throw new RuntimeException('Apache no respondió: ' . curl_error($handle));
    }
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    foreach ($responseHeaders as $line) {
        if (preg_match('/^Set-Cookie:\s*([^=;]+)=([^;]*)/i', $line, $match) === 1) {
            $cookies[$match[1]] = $match[2];
        }
    }
    return [
        'status' => $status,
        'body' => $responseBody,
        'headers' => $responseHeaders,
        'cookies' => $cookies,
    ];
};

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$secureHeaders = static function (array $response, string $surface) use ($assert): void {
    $headers = strtolower(implode("\n", $response['headers']));
    foreach ([
        'cache-control:',
        'content-security-policy:',
        'x-frame-options: deny',
        'x-content-type-options: nosniff',
    ] as $required) {
        $assert(
            str_contains($headers, $required),
            $surface . ' no entregó la cabecera ' . $required
        );
    }
};

foreach ([
    '/login.php' => 200,
    '/stop.php' => 200,
    '/stop/' => 200,
    '/recuperar.php' => 200,
    '/update_rescue.php' => 410,
] as $path => $expectedStatus) {
    $response = $request($path);
    $assert($response['status'] === $expectedStatus, $path . ' respondió ' . $response['status']);
    $secureHeaders($response, $path);
    $assert(
        preg_match('/SQLSTATE|PDOException|[A-Z]:\\\\|\/home\/|DB_PASSWORD|ML_CLIENT_SECRET/i', $response['body']) !== 1,
        $path . ' expuso información técnica o sensible.'
    );
}

foreach ([
    '/config.env',
    '/VERSION',
    '/bootstrap.php',
    '/PAUSE_MELI_API',
    '/PAUSE_ERP_AUTOMATION',
    '/app/Controllers/AuthController.php',
    '/jobs/process_sync_queue.php',
    '/storage/cache/cron-entry-state.json',
    '/vendor/autoload.php',
    '/resources/runtime-manifest.json',
    '/tests/route_contract.php',
] as $path) {
    $response = $request($path);
    $assert($response['status'] >= 400, $path . ' quedó accesible desde Apache.');
    $assert(!str_contains($response['body'], '<?php'), $path . ' expuso código PHP.');
}

$legacyPost = $request('/update_rescue.php', 'POST');
$assert($legacyPost['status'] === 410, 'El rescate heredado aceptó POST.');

foreach (['/login', '/login.php'] as $loginPath) {
    $login = $request($loginPath);
    $assert(
        preg_match('/name="_token" value="([^"]+)"/', $login['body'], $match) === 1,
        $loginPath . ' no presentó CSRF.'
    );
    if (!isset($match[1])) {
        continue;
    }
    $payload = http_build_query([
        '_token' => html_entity_decode($match[1], ENT_QUOTES, 'UTF-8'),
        'email' => 'invalid@example.invalid',
        'password' => 'invalid',
    ]);
    $missingOrigin = $request(
        $loginPath,
        'POST',
        ['Content-Type' => 'application/x-www-form-urlencoded'],
        $payload,
        $login['cookies']
    );
    $rejectedStatuses = [403, 423];
    $assert(
        in_array($missingOrigin['status'], $rejectedStatuses, true),
        $loginPath . ' aceptó POST sin origen (HTTP ' . $missingOrigin['status'] . ').'
    );
    $foreignOrigin = $request(
        $loginPath,
        'POST',
        [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Origin' => 'https://example.invalid',
        ],
        $payload,
        $login['cookies']
    );
    $assert(
        in_array($foreignOrigin['status'], $rejectedStatuses, true),
        $loginPath . ' aceptó origen ajeno (HTTP ' . $foreignOrigin['status'] . ').'
    );
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "PASS apache_recovery_surfaces_2265\n";
