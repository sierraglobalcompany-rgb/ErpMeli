<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use RuntimeException;
use PDO;
use Throwable;

final class ComponentSchemaContractService
{
    /** @return array{ready:bool,state:string,message:string,missing:list<string>} */
    public function status(string $component): array
    {
        try {
            $inspector = new SchemaInspectorService();
            if (!$inspector->hasTable('system_component_schema_contracts')) {
                return [
                    'ready' => false,
                    'state' => 'migration_required',
                    'message' => 'Falta instalar el contrato de integridad por componente.',
                    'missing' => ['114_operational_integrity_scope_2_21_2.sql'],
                ];
            }
            $pdo = Database::connectionFresh();
            $contracts = $pdo->prepare(
                'SELECT required_migration
                 FROM system_component_schema_contracts
                 WHERE component_key=? AND enabled=1 AND contract_kind="structural"
                 ORDER BY required_migration'
            );
            $contracts->execute([$component]);
            $required = array_values(array_unique(array_map(
                static fn (mixed $value): string => trim((string) $value),
                $contracts->fetchAll(PDO::FETCH_COLUMN)
            )));
            $applied = [];
            if ($inspector->hasTable('schema_migrations')) {
                $applied = array_fill_keys(array_map(
                    static fn (mixed $value): string => trim((string) $value),
                    $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)
                ), true);
            }
            $missing = array_values(array_filter(
                $required,
                static fn (string $migration): bool => $migration !== '' && !isset($applied[$migration])
            ));
            if ($missing !== []) {
                return [
                    'ready' => false,
                    'state' => 'migration_required',
                    'message' => 'Falta actualizar la estructura que necesita este proceso.',
                    'missing' => $missing,
                ];
            }
            return [
                'ready' => true,
                'state' => 'ready',
                'message' => 'El componente está listo.',
                'missing' => [],
            ];
        } catch (Throwable) {
            return [
                'ready' => false,
                'state' => 'unknown',
                'message' => 'No se pudo comprobar el esquema del componente.',
                'missing' => [],
            ];
        }
    }

    public function assertReady(string $component): void
    {
        $status = $this->status($component);
        if (!$status['ready']) {
            throw new RuntimeException($status['message']);
        }
    }
}
