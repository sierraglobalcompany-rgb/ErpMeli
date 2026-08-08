<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Services\MeliEndpointRegistry;
use App\Services\MeliApiClient;
use App\Services\MeliHttpTransportInterface;
use App\Modules\Shared\Services\MeliReadGateway;
use App\Modules\MeliGrowth\Services\GrowthSyncService;
use App\Services\MeliReadClientInterface;

$root = dirname(__DIR__);
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

MeliEndpointRegistry::assertOAuthTokenExchange('POST', '/oauth/token');
foreach ([
    ['GET', '/oauth/token'],
    ['POST', '/orders/search'],
    ['POST', '/oauth/token/extra'],
] as [$method, $path]) {
    $blocked = false;
    try {
        MeliEndpointRegistry::assertOAuthTokenExchange($method, $path);
    } catch (RuntimeException) {
        $blocked = true;
    }
    $assert($blocked, "Contrato OAuth demasiado amplio: {$method} {$path}");
}

$transport = new class implements MeliHttpTransportInterface {
    public bool $called = false;
    public function request(
        string $method,
        string $url,
        array $data,
        array $headers,
        bool $form,
        array $timeouts
    ): array {
        $this->called = true;
        throw new RuntimeException('El transporte no debía abrirse durante la parada.');
    }
};
$blocked = false;
try {
    (new MeliApiClient(0, $transport))->exchangeOAuthToken(['grant_type' => 'authorization_code']);
} catch (RuntimeException) {
    $blocked = true;
}
$assert($blocked && !$transport->called, 'OAuth abrió transporte a pesar de PAUSE_MELI_API.');

$oauth = (string) file_get_contents($root . '/app/Services/OAuthService.php');
$client = (string) file_get_contents($root . '/app/Services/MeliApiClient.php');
$budget = (string) file_get_contents($root . '/app/Services/ApiBudgetService.php');
$ads = (string) file_get_contents($root . '/app/Modules/MeliAds/Services/AdsSyncService.php');
$insights = (string) file_get_contents($root . '/app/Modules/MeliInsights/Services/InsightsSyncService.php');
$growth = (string) file_get_contents($root . '/app/Modules/MeliGrowth/Services/GrowthSyncService.php');
$postSale = (string) file_get_contents($root . '/app/Modules/MeliPostSale/Services/PostSaleSyncService.php');
$category = (string) file_get_contents($root . '/app/Services/MeliCategoryService.php');
$stock = (string) file_get_contents($root . '/app/Services/MeliItemStockService.php');
$claims = (string) file_get_contents($root . '/app/Services/ClaimSyncService.php');
$missedFeeds = (string) file_get_contents($root . '/app/Services/MissedFeedService.php');

$assert(!str_contains($oauth, 'new CurlMeliHttpTransport'), 'OAuth todavía evita guardia, pacing, presupuesto y telemetría.');
$assert(str_contains($oauth, 'exchangeOAuthToken('), 'OAuth no usa el transporte técnico protegido.');
$assert(str_contains($client, 'assertOAuthTokenExchange'), 'El cliente no fija el contrato OAuth exacto.');
$assert(str_contains($client, "'job_type' => 'oauth'"), 'OAuth no queda atribuido en telemetría.');
$exchangeStart = strpos($client, 'public function exchangeOAuthToken');
$exchangeStop = strpos($client, 'MeliEmergencyStopService())->assertAllowed()', $exchangeStart === false ? 0 : $exchangeStart);
$exchangeContract = strpos($client, 'assertOAuthTokenExchange', $exchangeStart === false ? 0 : $exchangeStart);
$assert($exchangeStart !== false && $exchangeStop !== false && $exchangeContract !== false && $exchangeStop < $exchangeContract,
    'El intercambio OAuth no comprueba la parada física antes del contrato/transporte.');
$completeStart = strpos($oauth, 'public function complete(');
$completeStop = strpos($oauth, 'MeliEmergencyStopService())->assertAllowed()', $completeStart === false ? 0 : $completeStart);
$completeClaim = strpos($oauth, 'processing_token_hash', $completeStart === false ? 0 : $completeStart);
$assert($completeStart !== false && $completeStop !== false && $completeClaim !== false && $completeStop < $completeClaim,
    'OAuth reclama el estado local antes de comprobar la parada física.');

$transportCall = strpos($client, '$this->transport->request(');
$dispatchedMark = strpos($client, 'markRemoteDispatched()', $transportCall === false ? 0 : $transportCall);
$assert($transportCall !== false && $dispatchedMark !== false && $transportCall < $dispatchedMark,
    'Se registra transporte remoto antes de que el transporte acepte la solicitud.');
$assert(str_contains($client, 'releaseReservation($budgetReservation)'),
    'Una barrera final puede consumir presupuesto sin transporte: falta compensación.');
$assert(str_contains($budget, 'request_count=GREATEST(request_count-1,0)'),
    'La devolución de presupuesto no está cercada contra conteos negativos.');
$assert(str_contains($client, 'Logger::redactString((string) ($decoded[\'message\']'),
    'Los mensajes de error remotos pueden llegar sin redacción a logs/excepciones.');
$assert(str_contains($client, 'new MeliApiException($safeMessage, $status ?: null, $requestId, $safeDecoded)'),
    'La excepción conserva el payload remoto sin redacción.');

foreach ([$ads, $insights, $growth, $postSale, $category, $stock, $claims, $missedFeeds] as $source) {
    $assert(
        str_contains($source, 'MeliEndpointRegistry::isConfirmed'),
        'Un flujo productivo conserva un endpoint no confirmado sin gate previo.'
    );
}

foreach ([
    '/advertising/advertisers',
    '/categories/MCO1',
    '/user-products/ABC/stock',
    '/users/123',
    '/messages/packs/123/sellers/456',
] as $path) {
    $assert(!MeliEndpointRegistry::isConfirmed('GET', $path), 'Un endpoint fuera del mapa quedó habilitado: ' . $path);
}

$assert(MeliEndpointRegistry::isConfirmed('GET', '/orders/search'), 'El gate rompió una lectura confirmada.');
$assert(MeliEndpointRegistry::isConfirmed('GET', '/shipments/123'), 'El gate rompió envíos confirmados.');

$fake = new class implements MeliReadClientInterface {
    public bool $called = false;
    public function get(string $path, array $query = [], array $meta = []): array
    {
        $this->called = true;
        return [];
    }
};
$gateway = new MeliReadGateway(static fn (int $accountId): MeliReadClientInterface => $fake);
$blocked = false;
try {
    $gateway->get('meli-growth', 9, '/seller-promotions/candidates/CANDIDATE-MCO1-1');
} catch (RuntimeException) {
    $blocked = true;
}
$assert($blocked && !$fake->called, 'Un cliente modular inyectado pudo saltarse el mapa API local.');

foreach ([
    ['promotion_candidate', 'CANDIDATE-MCO1-1'],
    ['promotion_offer', 'OFFER-MCO1-1'],
] as [$topic, $resourceId]) {
    $result = (new GrowthSyncService($gateway))->process([
        'job_type' => 'event_sync',
        'meli_account_id' => 9,
        'payload' => ['topic' => $topic, 'resource_id' => $resourceId],
    ]);
    $assert(
        ($result['status'] ?? '') === 'ignored_unsupported' && !$fake->called,
        'Growth intentó transportar un evento cuyo endpoint sigue en investigación: ' . $topic
    );
}

$eventStart = strpos($growth, 'private function processEvent');
$eventEnd = strpos($growth, 'private function syncCandidate');
$eventSource = $eventStart !== false && $eventEnd !== false
    ? substr($growth, $eventStart, $eventEnd - $eventStart)
    : '';
$eventGateCount = substr_count($eventSource, "MeliEndpointRegistry::isConfirmed('GET', \$path)");
$candidateCall = strpos($eventSource, 'return $this->syncCandidate');
$offerCall = strpos($eventSource, 'return $this->syncOffer');
$assert(
    $eventGateCount === 2 && $candidateCall !== false && $offerCall !== false,
    'Los eventos Growth no verifican el endpoint exacto antes de consultar.'
);

echo "PASS endpoint_contract_production_gate_22814\n";
