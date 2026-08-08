<?php

declare(strict_types=1);

namespace App\Modules\Shared\Services;

use App\Modules\Shared\Gateways\CoreReadGateway;

abstract class AbstractModuleSyncService
{
    /** @param array<string,mixed> $job @return array<string,mixed> */
    public function process(array $job): array
    {
        $gateway = new CoreReadGateway();
        $requested = isset($job['meli_account_id']) ? (int) $job['meli_account_id'] : 0;
        $accounts = $gateway->activeAccounts($requested > 0 ? $requested : null);
        if ($accounts === []) {
            return ['processed' => 0, 'errors' => 0, 'status' => 'no_accounts'];
        }
        $checkpoint = is_array($job['checkpoint'] ?? null) ? $job['checkpoint'] : [];
        $accountOffset = $requested > 0 ? 0 : max(0, (int) ($checkpoint['account_offset'] ?? 0));
        $account = $accounts[$accountOffset] ?? null;
        if (!is_array($account)) {
            return ['processed' => 0, 'errors' => 0, 'status' => 'completed'];
        }
        try {
            $result = $this->syncAccount((int) $account['id'], $account, $job);
        } catch (\Throwable) {
            return [
                'processed' => 0,
                'errors' => 1,
                'status' => 'retry',
                'stage' => 'account_error',
                'safe_message' => 'La cuenta no pudo actualizarse en este ciclo.',
                'checkpoint' => $checkpoint,
            ];
        }
        if (in_array((string) ($result['status'] ?? 'completed'), ['pending', 'retry', 'delayed', 'partial'], true)) {
            $result['checkpoint'] = is_array($result['checkpoint'] ?? null) ? $result['checkpoint'] : $checkpoint;
            $result['checkpoint']['account_offset'] = $accountOffset;
            return $result;
        }
        if ($requested < 1 && $accountOffset + 1 < count($accounts)) {
            return [
                'processed' => (int) ($result['processed'] ?? 0),
                'errors' => (int) ($result['errors'] ?? 0),
                'status' => 'pending',
                'stage' => 'next_account',
                'delay_seconds' => 5,
                'progress_current' => $accountOffset + 1,
                'progress_total' => count($accounts),
                'checkpoint' => ['account_offset' => $accountOffset + 1],
            ];
        }
        return $result + ['status' => 'completed'];
    }

    /** @param array<string,mixed> $account @param array<string,mixed> $job @return array<string,mixed> */
    abstract protected function syncAccount(int $accountId, array $account, array $job): array;
}
