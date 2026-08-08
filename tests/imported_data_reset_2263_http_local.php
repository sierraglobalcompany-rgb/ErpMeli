<?php

declare(strict_types=1);

/**
 * Prueba HTTP adversarial sobre MariaDB y servidor PHP desechables.
 *
 * No usa la configuración ni la base del ERP instalado. No crea backups y no
 * autoriza el job CLI; confirma que la superficie web nunca retire datos.
 */

$host = (string) (getenv('ERP_TEST_DB_HOST') ?: '127.0.0.1');
$port = (string) (getenv('ERP_TEST_DB_PORT') ?: '3306');
$user = (string) (getenv('ERP_TEST_DB_USER') ?: 'root');
$pass = (string) (getenv('ERP_TEST_DB_PASS') ?: '');
$root = dirname(__DIR__);
$database = 'erp_reset_http_' . bin2hex(random_bytes(5));
$temporary = sys_get_temp_dir() . '/erp-reset-http-' . bin2hex(random_bytes(6));
$cookie = $temporary . '/session.cookies';
$temporaryCookie = $temporary . '/temporary-session.cookies';
$router = $temporary . '/router.php';
$webPort = random_int(18100, 22900);
$base = 'http://127.0.0.1:' . $webPort;
$email = 'reset-http@example.test';
$password = 'Prueba-Segura-2263!';
$server = new PDO(
    'mysql:host=' . $host . ';port=' . $port . ';charset=utf8mb4',
    $user,
    $pass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
);
$process = null;
$pipes = [];

function httpResetAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{status:int,body:string,headers:string} */
function resetHttpRequest(
    string $url,
    string $cookie,
    ?array $post = null,
    ?string $origin = null
): array {
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('No se pudo abrir cURL.');
    }
    $headers = ['Accept: text/html'];
    if ($origin !== null) {
        $headers[] = 'Origin: ' . $origin;
    }
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookie,
        CURLOPT_COOKIEFILE => $cookie,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 2,
        // El análisis deliberadamente recorre la evidencia preservada. El
        // límite alto evita confundir esa comprobación local con un cuelgue.
        CURLOPT_TIMEOUT => 120,
    ]);
    if ($post !== null) {
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($post),
        ]);
    }
    $response = curl_exec($curl);
    if (!is_string($response)) {
        throw new RuntimeException('Falló HTTP local: ' . curl_error($curl));
    }
    $headerSize = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return [
        'status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function resetHttpToken(string $html): string
{
    if (preg_match('/name="_token" value="([^"]+)"/', $html, $match) !== 1) {
        throw new RuntimeException('La página no entregó token CSRF.');
    }
    return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
}

function removeResetHttpTree(string $path): void
{
    $resolved = realpath($path);
    $temp = realpath(sys_get_temp_dir());
    if (
        $resolved === false
        || $temp === false
        || !str_starts_with($resolved, $temp . DIRECTORY_SEPARATOR)
        || !str_starts_with(basename($resolved), 'erp-reset-http-')
    ) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($resolved);
}

try {
    if (!is_dir($temporary) && !mkdir($temporary, 0750, true) && !is_dir($temporary)) {
        throw new RuntimeException('No se pudo crear el entorno HTTP temporal.');
    }
    $server->exec(
        'CREATE DATABASE `' . $database
        . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    require $root . '/vendor/autoload.php';
    $pdo = new PDO(
        'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false]
    );
    $migrationDirectory = $temporary . '/migrations';
    mkdir($migrationDirectory, 0750, true);
    foreach (glob($root . '/database/migrations/*.sql') ?: [] as $sourceMigration) {
        $contents = (string) file_get_contents($sourceMigration);
        if (basename($sourceMigration) === '122_sale_financial_reconciliation_2_24_0.sql') {
            // MariaDB 10.4 local no ofrece JSON_TABLE. La base está vacía en
            // este punto, por lo que la adopción histórica no tendría filas.
            // Las tablas y contratos reales de la migración sí se ejecutan.
            $contents = (string) preg_replace(
                '/-- Migra expectativas ya verificadas.*?(?=INSERT INTO app_settings)/s',
                "-- Adopción histórica omitida únicamente en el fixture vacío de prueba HTTP.\n",
                $contents
            );
        }
        file_put_contents($migrationDirectory . '/' . basename($sourceMigration), $contents);
    }
    $migrations = (new \App\Services\Migrator(
        $pdo,
        $migrationDirectory
    ))->run();
    $unfinished = array_filter(
        $migrations,
        static fn (array $row): bool => !in_array(
            (string) ($row['status'] ?? ''),
            ['applied', 'adopted', 'skip'],
            true
        )
    );
    httpResetAssert($unfinished === [], 'Las migraciones HTTP no terminaron.');
    $pdo->prepare(
        'INSERT INTO users(name,email,password_hash,role,status)
         VALUES("Admin HTTP",? ,? ,"admin",1)'
    )->execute([$email, password_hash($password, PASSWORD_DEFAULT)]);
    $adminId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO companies(name,status) VALUES('Empresa HTTP',1)");
    $companyId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO user_company_access(user_id,company_id) VALUES(?,?)'
    )->execute([$adminId, $companyId]);
    $pdo->prepare(
        'INSERT INTO meli_accounts(company_id,account_name,status)
         VALUES(?,"Cuenta HTTP","conectado")'
    )->execute([$companyId]);
    $accountId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO meli_tokens
         (meli_account_id,access_token_encrypted,refresh_token_encrypted,expires_at)
         VALUES(?,"cipher-access","cipher-refresh",DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY))'
    )->execute([$accountId]);
    $pdo->exec(
        "INSERT INTO app_settings(setting_key,setting_value,setting_group)
         VALUES('qa.preserved_setting','preserved','qa')
         ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)"
    );
    $pdo->prepare(
        'INSERT INTO users
         (name,email,password_hash,role,status,is_temporary,expires_at,temporary_reason)
         VALUES("Admin temporal","temporary-reset@example.test",?,"admin",1,1,
                DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 DAY),"Prueba de permisos")'
    )->execute([password_hash($password, PASSWORD_DEFAULT)]);
    $temporaryAdminId = (int) $pdo->lastInsertId();
    $pdo->prepare(
        'INSERT INTO user_company_access(user_id,company_id) VALUES(?,?)'
    )->execute([$temporaryAdminId, $companyId]);
    $pdo->prepare(
        'INSERT INTO meli_orders
         (meli_account_id,external_order_id,total_amount,paid_amount,synced_at)
         VALUES(? ,990000000001,1000,1000,UTC_TIMESTAMP())'
    )->execute([$accountId]);
    $orderId = (int) $pdo->lastInsertId();

    $routerBody = "<?php\n"
        . 'define("ERP_RELEASE_ROOT",' . var_export($root, true) . ");\n"
        . 'define("ERP_INSTALLATION_ROOT",' . var_export($root, true) . ");\n"
        . 'define("ERP_SHARED_ROOT",' . var_export($temporary, true) . ");\n"
        . 'require ' . var_export($root . '/public/index.php', true) . ";\n";
    file_put_contents($router, $routerBody);
    $appKey = bin2hex(random_bytes(32));
    $storage = $temporary . '/storage';
    mkdir($storage, 0750, true);
    $installedPayload = [
        'version' => trim((string) file_get_contents($root . '/VERSION')),
        'last_migration' => '151_technical_retention_membership_2_26_3.sql',
        'completed_at' => gmdate(DATE_ATOM),
    ];
    $installedEncoded = json_encode(
        $installedPayload,
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    file_put_contents($storage . '/installed-release.json', json_encode([
        'payload' => $installedPayload,
        'signature' => hash_hmac('sha256', $installedEncoded, $appKey),
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    $environment = array_merge(is_array(getenv()) ? getenv() : [], [
        'DB_HOST' => $host,
        'DB_PORT' => $port,
        'DB_NAME' => $database,
        'DB_USER' => $user,
        'DB_PASS' => $pass,
        'APP_ENV' => 'local',
        'APP_URL' => $base,
        'APP_KEY' => $appKey,
        'APP_TIMEZONE' => 'America/Bogota',
        'ERP_PRIVATE_PATH' => $temporary . '/private',
        'ML_WRITE_ENABLED' => 'false',
        'SESSION_SECURE' => 'false',
    ]);
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $webPort, '-t', $root . '/public', $router],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $root,
        $environment
    );
    if (!is_resource($process)) {
        throw new RuntimeException('No se pudo iniciar PHP local.');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        usleep(100000);
        try {
            $probe = resetHttpRequest($base . '/login', $cookie);
            if ($probe['status'] === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
        }
    }
    httpResetAssert($ready, 'PHP local no quedó disponible.');

    $anonymous = resetHttpRequest($base . '/settings/imported-data-reset', $cookie);
    httpResetAssert(
        $anonymous['status'] === 302
        && str_contains($anonymous['headers'], '/login'),
        'La ruta no protegió el acceso anónimo.'
    );
    $login = resetHttpRequest($base . '/login', $cookie);
    $loginToken = resetHttpToken($login['body']);
    $authenticated = resetHttpRequest($base . '/login', $cookie, [
        '_token' => $loginToken,
        'email' => $email,
        'password' => $password,
    ], $base);
    httpResetAssert(
        in_array($authenticated['status'], [302, 303], true),
        'No fue posible autenticar el administrador local.'
    );
    $page = resetHttpRequest($base . '/settings/imported-data-reset', $cookie);
    httpResetAssert(
        $page['status'] === 200
        && str_contains($page['body'], 'Restablecer datos importados de Mercado Libre')
        && str_contains($page['body'], 'Esto sí retira copias importadas')
        && str_contains($page['body'], 'Solo limpiar ruido técnico')
        && str_contains($page['body'], 'Analizar sin borrar'),
        'El flujo inicial no cargó con lenguaje humano. HTTP=' . $page['status']
        . ' cabeceras=' . str_replace(["\r", "\n"], ' ', mb_substr($page['headers'], 0, 300))
        . ' cuerpo=' . mb_substr(strip_tags($page['body']), 0, 180)
    );
    $token = resetHttpToken($page['body']);

    $temporaryLogin = resetHttpRequest($base . '/login', $temporaryCookie);
    $temporaryToken = resetHttpToken($temporaryLogin['body']);
    $temporaryAuthenticated = resetHttpRequest($base . '/login', $temporaryCookie, [
        '_token' => $temporaryToken,
        'email' => 'temporary-reset@example.test',
        'password' => $password,
    ], $base);
    httpResetAssert(
        in_array($temporaryAuthenticated['status'], [302, 303], true),
        'No fue posible autenticar el administrador temporal.'
    );
    foreach (['/settings/database-maintenance', '/settings/imported-data-reset'] as $protectedPath) {
        $temporaryDenied = resetHttpRequest($base . $protectedPath, $temporaryCookie);
        httpResetAssert(
            $temporaryDenied['status'] === 403,
            'Un administrador temporal accedió a ' . $protectedPath . '.'
        );
    }

    $maintenancePage = resetHttpRequest($base . '/settings/database-maintenance', $cookie);
    httpResetAssert(
        $maintenancePage['status'] === 200
        && str_contains($maintenancePage['body'], 'Saneamiento conserva sus ventas importadas')
        && str_contains($maintenancePage['body'], 'Comparar con “Empezar de nuevo”'),
        'Saneamiento no explica claramente su diferencia con restablecer.'
    );
    $maintenanceToken = resetHttpToken($maintenancePage['body']);
    $maintenanceBadOrigin = resetHttpRequest(
        $base . '/settings/database-maintenance/analyze',
        $cookie,
        ['_token' => $maintenanceToken],
        'http://example.invalid'
    );
    httpResetAssert(
        $maintenanceBadOrigin['status'] === 403,
        'Saneamiento aceptó un origen ajeno.'
    );
    $maintenanceBadCsrf = resetHttpRequest(
        $base . '/settings/database-maintenance/analyze',
        $cookie,
        ['_token' => 'incorrecto'],
        $base
    );
    httpResetAssert(
        in_array($maintenanceBadCsrf['status'], [403, 419], true),
        'Saneamiento aceptó un CSRF incorrecto.'
    );
    $maintenanceAnalyzed = resetHttpRequest(
        $base . '/settings/database-maintenance/analyze',
        $cookie,
        ['_token' => $maintenanceToken],
        $base
    );
    httpResetAssert(
        $maintenanceAnalyzed['status'] === 303
        && str_contains($maintenanceAnalyzed['headers'], '/settings/database-maintenance?id='),
        'El análisis de saneamiento no terminó con PRG.'
    );
    $maintenanceId = (int) $pdo->query(
        'SELECT id FROM database_maintenance_sessions ORDER BY id DESC LIMIT 1'
    )->fetchColumn();
    httpResetAssert($maintenanceId > 0, 'Saneamiento no creó una sesión.');
    $maintenanceResult = resetHttpRequest(
        $base . '/settings/database-maintenance?id=' . $maintenanceId,
        $cookie
    );
    $maintenanceResultToken = resetHttpToken($maintenanceResult['body']);
    $maintenanceWrongPassword = resetHttpRequest(
        $base . '/settings/database-maintenance/start',
        $cookie,
        [
            '_token' => $maintenanceResultToken,
            'session_id' => $maintenanceId,
            'backup_id' => 1,
            'control_token' => 'browser-control-token-123456',
            'password' => 'incorrecta',
        ],
        $base
    );
    httpResetAssert(
        $maintenanceWrongPassword['status'] === 500,
        'Saneamiento no exigió reautenticación.'
    );
    $maintenanceNoBackup = resetHttpRequest(
        $base . '/settings/database-maintenance/start',
        $cookie,
        [
            '_token' => $maintenanceResultToken,
            'session_id' => $maintenanceId,
            'backup_id' => 0,
            'control_token' => 'browser-control-token-123456',
            'password' => $password,
        ],
        $base
    );
    httpResetAssert(
        $maintenanceNoBackup['status'] === 500,
        'Saneamiento aceptó comenzar sin la copia exacta.'
    );
    $retired = resetHttpRequest(
        $base . '/settings/database-maintenance/step',
        $cookie,
        [
            '_token' => $maintenanceResultToken,
            'session_id' => $maintenanceId,
            'control_token' => 'browser-control-token-123456',
            'idempotency_key' => 'http-must-not-delete-123456',
        ],
        $base
    );
    httpResetAssert($retired['status'] === 410, 'La ruta web step no quedó retirada.');
    $rebuildWithoutReauthentication = resetHttpRequest(
        $base . '/settings/database-maintenance/rebuild',
        $cookie,
        [
            '_token' => $maintenanceResultToken,
            'session_id' => $maintenanceId,
            'table' => 'api_request_logs',
            'confirmation' => 'RECUPERAR ESPACIO',
            'password' => 'incorrecta',
        ],
        $base
    );
    httpResetAssert(
        $rebuildWithoutReauthentication['status'] === 500,
        'La recuperación física no exigió reautenticación antes de crear el encargo CLI.'
    );
    $maintenanceStatus = resetHttpRequest(
        $base . '/settings/database-maintenance/status.json?id=' . $maintenanceId,
        $cookie
    );
    httpResetAssert($maintenanceStatus['status'] === 200, 'El JSON de saneamiento no respondió.');
    foreach ([
        'plan_json',
        'integrity_before',
        'integrity_after',
        'control_token_hash',
        'backup_id',
        'recovery_plan',
    ] as $forbidden) {
        httpResetAssert(
            !str_contains($maintenanceStatus['body'], $forbidden),
            'El JSON de saneamiento expuso ' . $forbidden . '.'
        );
    }

    $badOrigin = resetHttpRequest(
        $base . '/settings/imported-data-reset/analyze',
        $cookie,
        ['_token' => $token],
        'http://example.invalid'
    );
    httpResetAssert($badOrigin['status'] === 403, 'El origen ajeno no fue rechazado.');
    httpResetAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM imported_data_reset_requests')->fetchColumn() === 0,
        'El origen rechazado alcanzó el servicio.'
    );
    $badCsrf = resetHttpRequest(
        $base . '/settings/imported-data-reset/analyze',
        $cookie,
        ['_token' => 'incorrecto'],
        $base
    );
    httpResetAssert(
        in_array($badCsrf['status'], [403, 419], true),
        'El token CSRF incorrecto no fue rechazado.'
    );
    $analyzed = resetHttpRequest(
        $base . '/settings/imported-data-reset/analyze',
        $cookie,
        ['_token' => $token],
        $base
    );
    $latestHttpError = $analyzed['status'] >= 500
        ? ($pdo->query(
            "SELECT CONCAT(message,' | ',COALESCE(context_json,'')) "
            . "FROM system_logs WHERE level='error' ORDER BY id DESC LIMIT 1"
        )->fetchColumn() ?: '')
        : '';
    httpResetAssert(
        $analyzed['status'] === 303
        && str_contains($analyzed['headers'], '/settings/imported-data-reset?id='),
        'El análisis válido no usó redirección PRG. HTTP=' . $analyzed['status']
        . ' cabeceras=' . str_replace(["\r", "\n"], ' ', $analyzed['headers'])
        . ' cuerpo=' . mb_substr(strip_tags($analyzed['body']), 0, 180)
        . ' diagnostico=' . mb_substr((string) $latestHttpError, 0, 1200)
    );
    $requestId = (int) $pdo->query(
        'SELECT id FROM imported_data_reset_requests ORDER BY id DESC LIMIT 1'
    )->fetchColumn();
    httpResetAssert($requestId > 0, 'El análisis no creó su solicitud local.');
    httpResetAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM meli_orders WHERE id=' . $orderId)
            ->fetchColumn() === 1,
        'El análisis HTTP retiró una orden.'
    );

    $analysisPage = resetHttpRequest(
        $base . '/settings/imported-data-reset?id=' . $requestId,
        $cookie
    );
    $analysisToken = resetHttpToken($analysisPage['body']);
    $wrongPassword = resetHttpRequest(
        $base . '/settings/imported-data-reset/authorize',
        $cookie,
        [
            '_token' => $analysisToken,
            'request_id' => $requestId,
            'backup_id' => 1,
            'password' => 'incorrecta',
            'confirmation' => 'ELIMINAR DATOS DE MERCADO LIBRE',
            'challenge' => 'XXXXXX',
        ],
        $base
    );
    httpResetAssert($wrongPassword['status'] === 500, 'La reautenticación incorrecta no detuvo la autorización.');
    httpResetAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM meli_orders WHERE id=' . $orderId)
            ->fetchColumn() === 1,
        'Una autorización fallida retiró una orden.'
    );
    httpResetAssert(
        (int) $pdo->query('SELECT COUNT(*) FROM companies WHERE id=' . $companyId)
            ->fetchColumn() === 1
        && (int) $pdo->query('SELECT COUNT(*) FROM meli_accounts WHERE id=' . $accountId)
            ->fetchColumn() === 1
        && (int) $pdo->query('SELECT COUNT(*) FROM meli_tokens WHERE meli_account_id=' . $accountId)
            ->fetchColumn() === 1
        && (string) $pdo->query(
            "SELECT setting_value FROM app_settings WHERE setting_key='qa.preserved_setting'"
        )->fetchColumn() === 'preserved',
        'La superficie web cambió empresa, cuenta, token o configuración.'
    );

    $status = resetHttpRequest(
        $base . '/settings/imported-data-reset/status.json?id=' . $requestId,
        $cookie
    );
    httpResetAssert($status['status'] === 200, 'El estado JSON no respondió.');
    foreach ([
        'storage_name',
        'checksum_sha256',
        'manifest_sha256',
        'confirmation_challenge',
        'lease_owner',
        'integrity_sha256',
    ] as $forbidden) {
        httpResetAssert(
            !str_contains($status['body'], $forbidden),
            'El estado JSON expuso ' . $forbidden . '.'
        );
    }
    $payload = json_decode($status['body'], true, 512, JSON_THROW_ON_ERROR);
    httpResetAssert(
        (int) ($payload['request']['id'] ?? 0) === $requestId
        && ($payload['backup'] ?? null) === null,
        'El estado JSON sanitizado no representa el análisis.'
    );

    fwrite(
        STDOUT,
        "OK HTTP: ambos flujos; admin permanente; temporal bloqueado; origen; CSRF; "
        . "PRG; reautenticacion; backup exacto; JSON sanitizado; alcance preservado "
        . "y cero borrados web.\n"
    );
    if ((string) getenv('ERP_TEST_BROWSER_HOLD') === '1') {
        fwrite(
            STDOUT,
            'BROWSER_FIXTURE base=' . $base . ' email=' . $email
            . ' password=' . $password . PHP_EOL
        );
        while (true) {
            sleep(1);
        }
    }
} finally {
    if (is_resource($process)) {
        proc_terminate($process);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
    }
    try {
        $server->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    } catch (Throwable) {
    }
    removeResetHttpTree($temporary);
}
