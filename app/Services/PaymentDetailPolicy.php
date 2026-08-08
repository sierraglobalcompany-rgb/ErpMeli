<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class PaymentDetailPolicy
{
    public function shouldAttempt(int $accountId, int|string $paymentId): bool
    {
        // Mercado Libre no tiene habilitado para este ERP el detalle genérico /payments/{id}.
        // Los pagos operativos se conservan desde orders[].payments[]; evitar esta llamada
        // incluso si una instalación antigua dejó payments.expand_details_enabled=1.
        return false;
    }

    public function currentStatus(int $accountId, int|string $paymentId): array
    {
        $schema = new SchemaInspectorService();
        if (!$schema->hasTable('meli_payments') || !$schema->hasColumn('meli_payments', 'detail_status')) {
            return ['detail_status' => 'summary', 'detail_attempts' => 0];
        }
        $attempts = $schema->hasColumn('meli_payments', 'detail_attempts') ? 'detail_attempts' : '0 AS detail_attempts';
        $stmt = Database::connection()->prepare('SELECT detail_status, ' . $attempts . ' FROM meli_payments WHERE meli_account_id=:account AND external_payment_id=:payment LIMIT 1');
        $stmt->execute(['account' => $accountId, 'payment' => $paymentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : ['detail_status' => 'summary', 'detail_attempts' => 0];
    }

    public static function statusFromException(\Throwable $exception): string
    {
        return $exception instanceof MeliApiException && $exception->httpStatus === 404 ? 'unavailable' : 'error';
    }
}
