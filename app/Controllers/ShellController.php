<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Session;
use App\Services\ApiHealthAlertService;
use App\Services\SystemSafetyStatusService;
use App\Services\RequestContextService;
use App\Services\WebhookService;
use App\Services\BusinessScopeContext;
use Throwable;

final class ShellController
{
    public function snapshot(): never
    {
        Auth::requireLogin();
        Session::closeReadOnly();
        $payload = [
            'ok' => true,
            'context' => ['ok' => false, 'companies' => [], 'accounts' => []],
            'status' => ['ok' => false, 'unread' => 0, 'api_alert' => null],
        ];
        try {
            $options = (new RequestContextService())->options();
            $payload['context'] = [
                'ok' => true,
                'companies' => array_values(array_map(
                    static fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['name'],
                    ],
                    $options['companies']
                )),
                'accounts' => array_values(array_map(
                    static fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['account_name'],
                        'company_id' => (int) ($row['company_id'] ?? 0),
                    ],
                    $options['accounts']
                )),
            ];
        } catch (Throwable) {
            $payload['context']['message'] = 'Empresas y cuentas no están disponibles temporalmente.';
        }
        try {
            $payload['status'] = $this->statusPayload();
        } catch (Throwable) {
            $payload['status']['message'] = 'El estado secundario no pudo comprobarse.';
        }
        $this->json($payload);
    }

    public function context(): never
    {
        Auth::requireLogin();
        Session::closeReadOnly();
        try {
            $options = (new RequestContextService())->options();
            $this->json([
                'ok' => true,
                'companies' => array_values(array_map(
                    static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
                    $options['companies']
                )),
                'accounts' => array_values(array_map(
                    static fn (array $row): array => [
                        'id' => (int) $row['id'],
                        'name' => (string) $row['account_name'],
                        'company_id' => (int) ($row['company_id'] ?? 0),
                    ],
                    $options['accounts']
                )),
            ]);
        } catch (Throwable) {
            $this->json([
                'ok' => false,
                'message' => 'No se pudieron cargar empresas y cuentas. El resto del ERP sigue disponible.',
            ], 503);
        }
    }

    public function status(): never
    {
        Auth::requireLogin();
        Session::closeReadOnly();
        $this->json($this->statusPayload());
    }

    /** @return array<string,mixed> */
    private function statusPayload(): array
    {
        $safety = (new SystemSafetyStatusService())->status();
        if ($safety['api'] === 'stopped' || $safety['automation'] === 'stopped') {
            return [
                'ok' => true,
                'unread' => 0,
                'safety' => $safety,
                'api_alert' => [
                    'level' => 'maintenance',
                    'title' => (string) $safety['label'],
                    'message' => $safety['api'] === 'stopped'
                        ? 'Las consultas remotas están bloqueadas. La navegación local permanece disponible.'
                        : 'Las colas están detenidas. Los trabajos permanecen guardados.',
                ],
            ];
        }
        $alert = Auth::role() === 'admin' && !Auth::isTemporary()
            ? (new ApiHealthAlertService())->current()
            : null;
        $summary = (new WebhookService())->unreadSummary(
            (new BusinessScopeContext())->accountIds((int) Auth::id())
        );
        return [
            'ok' => true,
            'unread' => max(0, (int) ($summary['unread'] ?? 0)),
            'api_alert' => $alert,
            'safety' => $safety,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
