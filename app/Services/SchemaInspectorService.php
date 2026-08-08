<?php

declare(strict_types=1);

namespace App\Services;

use Throwable;

final class SchemaInspectorService
{
    private static ?InformationSchemaGateway $gateway = null;
    private ?InformationSchemaGateway $injectedGateway;

    /** @var array<string, bool> */
    private static array $tableCache = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private static array $columnCache = [];

    public function __construct(?InformationSchemaGateway $gateway = null)
    {
        $this->injectedGateway = $gateway;
    }

    public function hasTable(string $table): bool
    {
        if (!$this->isSafeIdentifier($table)) {
            return false;
        }
        if (array_key_exists($table, self::$tableCache)) {
            return self::$tableCache[$table];
        }
        try {
            return self::$tableCache[$table] = $this->gateway()->hasTable($table);
        } catch (Throwable) {
            return self::$tableCache[$table] = false;
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function columns(string $table): array
    {
        if (!$this->isSafeIdentifier($table) || !$this->hasTable($table)) {
            return [];
        }
        if (array_key_exists($table, self::$columnCache)) {
            return self::$columnCache[$table];
        }
        try {
            $columns = $this->gateway()->columns($table);
            self::$columnCache[$table] = $columns;
            return $columns;
        } catch (Throwable) {
            return self::$columnCache[$table] = [];
        }
    }

    public function hasColumn(string $table, string $column): bool
    {
        return array_key_exists($column, $this->columns($table));
    }

    /**
     * @param list<string> $expected
     * @return list<string>
     */
    public function missingColumns(string $table, array $expected): array
    {
        $columns = $this->columns($table);
        return array_values(array_filter($expected, static fn (string $column): bool => !array_key_exists($column, $columns)));
    }

    /**
     * Comprueba un contrato completo con una sola lectura de information_schema.
     *
     * @param array<string,list<string>> $requirements
     * @return list<string>
     */
    public function missingRequirements(array $requirements): array
    {
        $safe = [];
        foreach ($requirements as $table => $columns) {
            if (!$this->isSafeIdentifier((string) $table)) {
                continue;
            }
            $safe[(string) $table] = array_values(array_filter(
                array_map('strval', $columns),
                fn (string $column): bool => $this->isSafeIdentifier($column)
            ));
        }
        if ($safe === []) {
            return [];
        }

        try {
            $available = $this->gateway()->columnNamesForTables(array_keys($safe));
        } catch (Throwable) {
            return array_keys($safe);
        }

        $missing = [];
        foreach ($safe as $table => $columns) {
            $present = $available[$table] ?? [];
            if ($present === []) {
                $missing[] = $table;
                self::$tableCache[$table] = false;
                self::$columnCache[$table] = [];
                continue;
            }
            self::$tableCache[$table] = true;
            foreach ($columns as $column) {
                if (!isset($present[$column])) {
                    $missing[] = $table . '.' . $column;
                }
            }
        }
        return $missing;
    }

    private function isSafeIdentifier(string $identifier): bool
    {
        return preg_match('/^[a-zA-Z0-9_]+$/', $identifier) === 1;
    }

    public static function clearCache(?string $table = null): void
    {
        if ($table === null) {
            self::$tableCache = [];
            self::$columnCache = [];
            self::$gateway?->clear();
            self::$gateway = null;
            return;
        }
        unset(self::$tableCache[$table], self::$columnCache[$table]);
    }

    private function gateway(): InformationSchemaGateway
    {
        return $this->injectedGateway ?? (self::$gateway ??= new InformationSchemaGateway());
    }
}
