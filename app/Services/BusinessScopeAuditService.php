<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Contrato estático y acotado para los caminos operativos críticos.
 *
 * No intenta interpretar SQL arbitrario. Verifica que los gateways que pueden
 * exponer datos comerciales conserven los marcadores de alcance exigidos por
 * el ERP. La prueba conductual de MariaDB complementa este diagnóstico.
 */
final class BusinessScopeAuditService
{
    /** @return array{status:string,label:string,message:string,checks:list<array<string,string>>} */
    public function inspect(): array
    {
        $root = dirname(__DIR__, 2);
        $contracts = [
            'Control de ventas' => [
                $root . '/app/Services/SalesAuditAccessGateway.php',
                ['BusinessScopeContext', 'meli_account_id'],
            ],
            'Contexto autorizado' => [
                $root . '/app/Services/BusinessScopeContext.php',
                ['user_company_access', 'accountPredicate', 'company_id'],
            ],
            'Campañas dirigidas' => [
                $root . '/app/Services/ManualCampaignService.php',
                ['meli_account_id', 'company_id'],
            ],
            'Notificaciones' => [
                $root . '/app/Services/NotificationWorkItemService.php',
                ['meli_account_id', 'company_id'],
            ],
        ];

        $checks = [];
        $failed = 0;
        foreach ($contracts as $label => [$path, $markers]) {
            $source = is_file($path) ? (string) file_get_contents($path) : '';
            $missing = array_values(array_filter(
                $markers,
                static fn (string $marker): bool => !str_contains($source, $marker)
            ));
            $ok = $source !== '' && $missing === [];
            if (!$ok) {
                $failed++;
            }
            $checks[] = [
                'component' => $label,
                'status' => $ok ? 'ok' : 'failed',
                'message' => $ok
                    ? 'El acceso exige empresa y cuenta explícitas.'
                    : 'Falta el contrato de alcance: ' . implode(', ', $missing),
            ];
        }

        return [
            'status' => $failed === 0 ? 'ok' : 'failed',
            'label' => $failed === 0 ? 'Aislamiento verificado' : 'Aislamiento requiere revisión',
            'message' => $failed === 0
                ? 'Los caminos críticos declaran empresa y cuenta antes de leer recursos comerciales.'
                : $failed . ' caminos críticos no cumplen el contrato estático de alcance.',
            'checks' => $checks,
        ];
    }
}
