<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class CompanyOptionService
{
    /** @return list<array<string,mixed>> */
    public function authorizedActive(): array
    {
        return $this->authorized(false);
    }

    /** @return list<array<string,mixed>> */
    public function authorizedNotDeleted(): array
    {
        return $this->authorized(true);
    }

    /**
     * Empresas válidas para selectores operativos.
     *
     * Regla 2.8.16:
     * - no mostrar eliminadas lógicamente;
     * - no mostrar inactivas en operaciones nuevas;
     * - no fusionar ni borrar duplicados.
     */
    public function active(): array
    {
        return Database::connection()
            ->query('SELECT id,name,nit,legal_name,status FROM companies WHERE deleted_at IS NULL AND status=1 ORDER BY name,id')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Empresas no eliminadas para pantallas administrativas donde sí conviene ver inactivas.
     */
    public function notDeleted(): array
    {
        return Database::connection()
            ->query('SELECT id,name,nit,legal_name,status FROM companies WHERE deleted_at IS NULL ORDER BY status DESC,name,id')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Diagnóstico seguro: nombres activos repetidos. No realiza merge automático.
     */
    public function duplicateActiveNames(): array
    {
        $stmt = Database::connection()->query(
            "SELECT LOWER(TRIM(name)) name_key,
                    MIN(name) display_name,
                    COUNT(*) total,
                    GROUP_CONCAT(id ORDER BY id SEPARATOR ',') ids
             FROM companies
             WHERE deleted_at IS NULL AND status=1
             GROUP BY LOWER(TRIM(name))
             HAVING COUNT(*) > 1
             ORDER BY MIN(name)"
        );

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function authorized(bool $includeInactive): array
    {
        $ids = (new AuthorizedBusinessScope())->companyIds();
        if ($ids === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $status = $includeInactive ? '' : ' AND status=1';
        $stmt = Database::connection()->prepare(
            'SELECT id,name,nit,legal_name,status FROM companies
             WHERE deleted_at IS NULL' . $status . ' AND id IN (' . $placeholders . ')
             ORDER BY status DESC,name,id'
        );
        $stmt->execute($ids);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
