<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class ApiErrorSummaryService
{
    /** @param list<int>|null $accountIds */
    public function recentGrouped(int $limit = 80, ?array $accountIds = null): array
    {
        try {
            $accountIds = $accountIds === null
                ? null
                : array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn (int $id): bool => $id > 0)));
            if ($accountIds === []) {
                return [];
            }
            $scope = $accountIds === null
                ? ''
                : ' WHERE e.meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')';
            $sql = "SELECT e.meli_account_id,a.account_name,e.method,e.endpoint_path,e.http_status,e.error_code,
                           MIN(e.created_at) first_seen_at,MAX(e.created_at) last_seen_at,COUNT(*) repetitions,
                           SUBSTRING_INDEX(GROUP_CONCAT(e.safe_message ORDER BY e.created_at DESC SEPARATOR ' || '),' || ',1) safe_message
                    FROM api_error_logs e
                    LEFT JOIN meli_accounts a ON a.id=e.meli_account_id
                    {$scope}
                    GROUP BY e.meli_account_id,a.account_name,e.method,e.endpoint_path,e.http_status,e.error_code
                    ORDER BY last_seen_at DESC
                    LIMIT ?";
            $stmt = Database::connection()->prepare($sql);
            $position = 1;
            foreach ($accountIds ?? [] as $accountId) {
                $stmt->bindValue($position++, $accountId, PDO::PARAM_INT);
            }
            $stmt->bindValue($position, max(1, min(500, $limit)), PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$row) {
                $row['recommendation'] = $this->recommendation((int) ($row['http_status'] ?? 0), (string) $row['endpoint_path']);
            }
            return $rows;
        } catch (Throwable $e) {
            Logger::write('warning', 'No se pudieron agrupar errores API.', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Remote transport is the authority for an operational 429 alert.
     * Legacy api_error_logs do not retain reached_remote, so they cannot
     * distinguish an old synthetic status from a Mercado Libre response.
     *
     * @param list<int> $accountIds
     * @param list<int> $companyIds
     * @return list<array<string,mixed>>
     */
    public function recentRemoteRateLimits(int $limit, array $accountIds, array $companyIds): array
    {
        $accountIds = array_values(array_unique(array_filter(array_map('intval', $accountIds), static fn(int $id): bool => $id > 0)));
        $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds), static fn(int $id): bool => $id > 0)));
        if ($accountIds === [] || $companyIds === []) {
            return [];
        }
        try {
            $statement = Database::connection()->prepare(
                'SELECT l.meli_account_id,MAX(l.company_id) company_id,MAX(l.endpoint_path) endpoint_path,
                        MAX(l.created_at) last_seen_at,COUNT(*) repetitions
                 FROM api_request_logs l
                 WHERE l.meli_account_id IN (' . implode(',', array_fill(0, count($accountIds), '?')) . ')
                   AND l.company_id IN (' . implode(',', array_fill(0, count($companyIds), '?')) . ')
                   AND l.reached_remote=1 AND l.http_status=429
                   AND l.created_at>=DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 72 HOUR)
                 GROUP BY l.meli_account_id,l.endpoint_path
                 ORDER BY last_seen_at DESC
                 LIMIT ?'
            );
            $position = 1;
            foreach ($accountIds as $accountId) {
                $statement->bindValue($position++, $accountId, PDO::PARAM_INT);
            }
            foreach ($companyIds as $companyId) {
                $statement->bindValue($position++, $companyId, PDO::PARAM_INT);
            }
            $statement->bindValue($position, max(1, min(100, $limit)), PDO::PARAM_INT);
            $statement->execute();
            return $statement->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private function recommendation(int $status, string $endpoint): string
    {
        if ($status === 404 && str_contains($endpoint, '/payments/')) {
            return 'Detalle de pago no confirmado; se usa el resumen incluido en la orden y no se insiste.';
        }
        if ($status === 401) return 'Revisar expiración OAuth; intentar refresh token controlado.';
        if ($status === 403) return 'Pausar la cuenta y revisar permisos/bloqueos de aplicación.';
        if ($status === 429) return 'Reducir frecuencia, respetar Retry-After y usar backoff.';
        if ($status >= 500) return 'Reintentar pocas veces con backoff; no ejecutar rangos grandes.';
        return 'Revisar endpoint, permisos y payload en el mapa API.';
    }
}
