<?php

declare(strict_types=1);

namespace App\Services;

final class ContextHelpRepository
{
    /** @return array<string,string>|null */
    public function get(string $key): ?array
    {
        return $this->definitions()[$key] ?? null;
    }

    /** @return array<string,array<string,string>> */
    private function definitions(): array
    {
        return [
            'automation.queue' => [
                'title' => 'Cola completa',
                'what' => 'Reúne los trabajos de cron y workers sin moverlos de sus tablas originales.',
                'when' => 'Úsela para saber qué está pendiente y por qué espera.',
                'data' => 'Solo muestra información operativa sanitizada.',
                'remote' => 'Abrir esta pantalla no consulta Mercado Libre.',
                'impact' => 'Ninguno; es de solo lectura.',
                'cancel' => 'No aplica.',
                'risk' => 'Ninguno.',
                'time' => 'Normalmente menos de dos segundos.',
            ],
            'automation.preview' => [
                'title' => 'Próxima ejecución',
                'what' => 'Simula qué escogerá el siguiente ciclo del cron.',
                'when' => 'Revísela antes de activar el cron o cuando un trabajo no avance.',
                'data' => 'No reserva ni cambia trabajos.',
                'remote' => 'No consulta Mercado Libre.',
                'impact' => 'Ninguno.',
                'cancel' => 'No aplica.',
                'risk' => 'Ninguno.',
                'time' => 'Inmediato.',
            ],
            'sales.audit.exact' => [
                'title' => 'Auditoría exacta',
                'what' => 'Obtiene una lista mensual única de órdenes y la compara con el ERP.',
                'when' => 'Úsela para comprobar faltantes o fechas antes de cerrar un periodo.',
                'data' => 'Crea un snapshot auditable; no borra órdenes.',
                'remote' => 'Sí, consulta órdenes en Mercado Libre por páginas.',
                'impact' => 'Consume presupuesto API de forma gradual.',
                'cancel' => 'Puede pausarse y continuar mediante cron.',
                'risk' => 'Bajo; respeta presupuesto, pausas y Retry-After.',
                'time' => 'Depende del mes y volumen; la cola mostrará una estimación.',
            ],
            'products.unlinked.suggest' => [
                'title' => 'Generar sugerencias',
                'what' => 'Compara SKU y nombres para proponer vínculos con productos de bodega.',
                'when' => 'Úselo cuando haya ventas de publicaciones todavía no vinculadas.',
                'data' => 'Crea sugerencias locales; no vincula automáticamente.',
                'remote' => 'No consulta Mercado Libre.',
                'impact' => 'Solo datos internos de apoyo.',
                'cancel' => 'Las sugerencias pueden ignorarse.',
                'risk' => 'Ninguno para Mercado Libre.',
                'time' => 'Depende de la cantidad de productos locales.',
            ],
            'alerts.refresh' => [
                'title' => 'Actualizar alertas',
                'what' => 'Recalcula señales operativas a partir de datos locales y salud API.',
                'when' => 'Úselo después de una reparación o para confirmar que un problema terminó.',
                'data' => 'Actualiza alertas locales.',
                'remote' => 'No consulta Mercado Libre.',
                'impact' => 'Puede crear o refrescar alertas; no cambia ventas.',
                'cancel' => 'No requiere cancelación.',
                'risk' => 'Ninguno para Mercado Libre.',
                'time' => 'Normalmente pocos segundos.',
            ],
            'imports.to_warehouse' => [
                'title' => 'Importar a bodega',
                'what' => 'Crea productos internos a partir de publicaciones ya almacenadas en el ERP.',
                'when' => 'Úselo para comenzar a gestionar costo, inventario y vinculación local.',
                'data' => 'Crea o actualiza productos internos seleccionados.',
                'remote' => 'No escribe ni consulta Mercado Libre.',
                'impact' => 'Modifica únicamente la bodega local.',
                'cancel' => 'Revise la selección antes de confirmar.',
                'risk' => 'Ninguno para Mercado Libre.',
                'time' => 'Normalmente inmediato para lotes pequeños.',
            ],
        ];
    }
}
