<?php

declare(strict_types=1);

namespace App\Services;

final class UiLabelPresenter
{
    public static function syncType(?string $value): string
    {
        return match (self::key($value)) {
            'orders', 'order', 'orders_sync' => 'Órdenes',
            'shipments', 'shipment' => 'Envíos',
            'packs', 'pack' => 'Paquetes',
            'items', 'products', 'items_sync', 'product_review' => 'Productos Mercado Libre',
            'questions', 'question' => 'Preguntas',
            'claims', 'claim' => 'Reclamos',
            'billing', 'financial', 'financial_recalc' => 'Conciliación financiera',
            'webhooks', 'webhook', 'orders_event_sync' => 'Notificaciones de ventas',
            '' => 'Sin tipo',
            default => self::humanize((string) $value),
        };
    }

    public static function status(?string $value): string
    {
        return match (self::key($value)) {
            'active' => 'Activo',
            'approved' => 'Aprobado',
            'basic' => 'Datos básicos',
            'cancelled', 'canceled' => 'Cancelado',
            'closed' => 'Finalizado',
            'complete', 'completed', 'applied', 'matched' => 'Completado',
            'difference' => 'Con diferencia',
            'empty' => 'Sin elementos',
            'enrichment_pending' => 'Pendiente de completar',
            'error', 'failed' => 'Con error',
            'inactive' => 'Inactivo',
            'opened', 'open' => 'Abierto',
            'paid' => 'Pagado',
            'partial' => 'Parcial',
            'processed' => 'Procesado',
            'quarantined' => 'En revisión técnica',
            'retry', 'waiting_retry' => 'Pendiente de reintento',
            'waiting_deadline' => 'Esperando el próximo ciclo',
            'waiting_rhythm' => 'Esperando ritmo seguro',
            'waiting_budget' => 'Esperando presupuesto de consultas',
            'waiting_api' => 'Esperando reactivación de Mercado Libre',
            'waiting_schedule' => 'Programado para después',
            'waiting_lock' => 'Otro proceso tiene el turno',
            'repairable' => 'Se puede reparar',
            'action_required' => 'Necesita una decisión',
            'remote_result_uncertain' => 'Resultado remoto por confirmar',
            'skipped_expected', 'ignored' => 'Omitido de forma segura',
            'duplicate' => 'Duplicado incorporado',
            'unknown_topic' => 'Evento informativo',
            'circuit_breaker_wait' => 'Protección temporal activa',
            'paused' => 'Pausado',
            'pending', 'queued' => 'Pendiente',
            'ready' => 'Listo',
            'rejected' => 'Rechazado',
            'running', 'processing', 'applying', 'scanning' => 'En proceso',
            'under_review', 'manual_review' => 'En revisión',
            '' => 'Sin estado',
            default => self::humanize((string) $value),
        };
    }

    /**
     * Texto seguro para una vista. Los detalles del driver pertenecen al
     * diagnóstico privado y nunca deben presentarse como instrucción humana.
     */
    public static function safeOperationMessage(?string $message, ?string $diagnosticId = null): string
    {
        $message = trim((string) $message);
        if ($message === '') {
            return $diagnosticId !== null && trim($diagnosticId) !== ''
                ? 'No se pudo completar el paso. Diagnóstico ' . trim($diagnosticId) . '.'
                : 'No se pudo completar el paso. Abra el detalle para ver la acción recomendada.';
        }

        $technicalPatterns = [
            '/SQLSTATE\s*\[/i',
            '/PDOException/i',
            '/(?:SELECT|INSERT|UPDATE|DELETE)\s+[^\r\n]{12,}/i',
            '/(?:unknown column|illegal mix of collations|access denied for user)/i',
            '/(?:[A-Z]:\\\\|\/home\/|\/var\/www\/)/i',
        ];
        foreach ($technicalPatterns as $pattern) {
            if (preg_match($pattern, $message) === 1) {
                return $diagnosticId !== null && trim($diagnosticId) !== ''
                    ? 'Ocurrió un problema local. Diagnóstico ' . trim($diagnosticId) . '.'
                    : 'Ocurrió un problema local. Revise el diagnóstico antes de reintentar.';
            }
        }

        $message = preg_replace('/\s+/', ' ', $message) ?: $message;
        return mb_strlen($message) > 240 ? mb_substr($message, 0, 237) . '…' : $message;
    }

    public static function catalogVisibility(?string $value): string
    {
        return match (self::key($value)) {
            'public' => 'Público',
            'private_token' => 'Privado por enlace',
            'private_password' => 'Clave para empleados',
            'internal_only' => 'Solo usuarios del ERP',
            default => self::status($value),
        };
    }

    public static function financialPhase(?string $value): string
    {
        return match (self::key($value)) {
            'local_recalc' => 'Cálculo local',
            'detect_missing' => 'Revisión de datos faltantes',
            'billing_import' => 'Importación de cobros',
            'billing_apply' => 'Aplicación de cobros',
            'final_recalc' => 'Conciliación final',
            default => self::status($value),
        };
    }

    public static function apiHealth(?string $risk): string
    {
        return match (self::key($risk)) {
            'critical' => 'Crítico',
            'high', 'attention' => 'Requiere atención',
            'medium' => 'Atención',
            'paused' => 'Consultas pausadas',
            'recovered' => 'Recuperado',
            'unknown' => 'No comprobado',
            'healthy', 'low' => 'Saludable',
            default => 'No comprobado',
        };
    }

    public static function apiOperation(?string $method, ?string $path): string
    {
        $path = strtolower((string) $path);
        return match (true) {
            str_contains($path, '/description') => 'Descripción de producto',
            str_contains($path, '/user-products/') && str_contains($path, '/stock') => 'Stock por origen',
            str_contains($path, '/shipments/') => 'Detalle de envío',
            str_contains($path, '/orders/') => 'Detalle de orden',
            str_contains($path, '/orders/search') => 'Búsqueda de órdenes',
            str_contains($path, '/items/') => 'Detalle de producto',
            str_contains($path, '/questions/') => 'Detalle de pregunta',
            str_contains($path, '/questions/search') || str_ends_with($path, '/questions') => 'Sincronización de preguntas',
            str_contains($path, '/claims/') => 'Detalle de reclamo',
            str_contains($path, '/billing/') => 'Conciliación de cobros',
            str_contains($path, '/packs/') => 'Detalle de paquete',
            default => trim(strtoupper((string) $method) . ' · Operación de Mercado Libre'),
        };
    }

    public static function auditDifference(int $difference): string
    {
        if ($difference > 0) {
            return number_format($difference, 0, ',', '.') . ' ' . ($difference === 1 ? 'faltante' : 'faltantes');
        }
        if ($difference < 0) {
            $count = abs($difference);
            return number_format($count, 0, ',', '.') . ' ' . ($count === 1 ? 'sobrante' : 'sobrantes');
        }
        return 'Sin diferencia';
    }

    private static function key(?string $value): string
    {
        return strtolower(trim((string) $value));
    }

    private static function humanize(string $value): string
    {
        $value = trim(str_replace(['_', '-'], ' ', $value));
        return $value === '' ? 'Sin datos' : ucfirst($value);
    }
}
