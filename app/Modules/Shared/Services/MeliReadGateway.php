<?php

declare(strict_types=1);

namespace App\Modules\Shared\Services;

use App\Services\MeliApiClient;
use App\Services\MeliEndpointRegistry;
use App\Services\MeliReadClientInterface;
use Closure;
use RuntimeException;

final class MeliReadGateway
{
    /** @param null|Closure(int):MeliReadClientInterface $clientFactory */
    public function __construct(private readonly ?Closure $clientFactory = null)
    {
    }

    /** @return array<string,mixed> */
    public function get(string $moduleId, int $accountId, string $path, array $query = [], string $jobType = 'module_read'): array
    {
        if (!str_starts_with($moduleId, 'meli-')) {
            throw new RuntimeException('Identidad de módulo inválida.');
        }
        // El gateway es una frontera de producción, incluso cuando recibe un
        // cliente inyectado. No delegar el contrato exclusivamente al cliente
        // HTTP evita que un adaptador o prueba doble pueda saltarse el mapa.
        MeliEndpointRegistry::assertDocumented('GET', $path);
        $client = $this->clientFactory !== null
            ? ($this->clientFactory)($accountId)
            : new MeliApiClient($accountId);
        return $client->get($path, $query, [
            'job_type' => $jobType,
            'source' => 'module:' . $moduleId,
            'bulk' => false,
            'account_id' => $accountId,
            'module_id' => $moduleId,
        ]);
    }
}
