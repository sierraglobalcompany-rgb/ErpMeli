<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use RuntimeException;

/**
 * Única puerta de lectura para information_schema.
 *
 * MariaDB puede asignar una collation distinta a las columnas de
 * information_schema. Las comparaciones binarias evitan que esa diferencia
 * detenga el runtime con HY000/1267.
 */
final class InformationSchemaGateway
{
    private PDO $pdo;
    private ?string $database = null;

    /** @var array<string,mixed> */
    private array $cache = [];

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::connection();
    }

    public function database(): string
    {
        if ($this->database !== null) {
            return $this->database;
        }
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        if ($database === '') {
            throw new RuntimeException('No fue posible identificar la base de datos activa.');
        }
        return $this->database = $database;
    }

    public function hasTable(string $table): bool
    {
        $this->assertIdentifier($table);
        $key = 'table:' . $table;
        if (array_key_exists($key, $this->cache)) {
            return (bool) $this->cache[$key];
        }
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.TABLES
             WHERE BINARY TABLE_SCHEMA=BINARY :schema
               AND BINARY TABLE_NAME=BINARY :table_name
             LIMIT 1'
        );
        $stmt->execute(['schema' => $this->database(), 'table_name' => $table]);
        return $this->cache[$key] = (bool) $stmt->fetchColumn();
    }

    /** @return list<string> */
    public function baseTables(): array
    {
        // El inventario se usa para bloquear schema drift durante tareas
        // destructivas; por eso no se reutiliza entre comprobaciones.
        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE BINARY TABLE_SCHEMA=BINARY :schema
               AND TABLE_TYPE="BASE TABLE"
             ORDER BY TABLE_NAME'
        );
        $stmt->execute(['schema' => $this->database()]);
        return array_map(
            'strval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    /**
     * Comprueba varias tablas en una sola lectura de information_schema.
     *
     * @param list<string> $tables
     * @return array<string,bool>
     */
    public function tablesExist(array $tables): array
    {
        $tables = array_values(array_unique(array_map('strval', $tables)));
        if ($tables === []) {
            return [];
        }

        $result = [];
        $conditions = [];
        $params = ['schema' => $this->database()];
        foreach ($tables as $index => $table) {
            $this->assertIdentifier($table);
            $result[$table] = false;
            $parameter = 'table_' . $index;
            $conditions[] = 'BINARY TABLE_NAME=BINARY :' . $parameter;
            $params[$parameter] = $table;
        }
        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE BINARY TABLE_SCHEMA=BINARY :schema
               AND (' . implode(' OR ', $conditions) . ')'
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $name = (string) $table;
            if (array_key_exists($name, $result)) {
                $result[$name] = true;
                $this->cache['table:' . $name] = true;
            }
        }
        foreach ($result as $name => $exists) {
            $this->cache['table:' . $name] = $exists;
        }
        return $result;
    }

    /** @return array<string,array<string,mixed>> */
    public function columns(string $table): array
    {
        $this->assertIdentifier($table);
        $key = 'columns:' . $table;
        if (isset($this->cache[$key]) && is_array($this->cache[$key])) {
            return $this->cache[$key];
        }
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME AS Field,DATA_TYPE AS Type,IS_NULLABLE AS `Null`,
                    COLUMN_KEY AS `Key`,COLUMN_DEFAULT AS `Default`,EXTRA AS Extra,
                    COLUMN_TYPE AS ColumnType
             FROM information_schema.COLUMNS
             WHERE BINARY TABLE_SCHEMA=BINARY :schema
               AND BINARY TABLE_NAME=BINARY :table_name
             ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute(['schema' => $this->database(), 'table_name' => $table]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) ($row['Field'] ?? '');
            if ($name !== '') {
                $result[$name] = $row;
            }
        }
        return $this->cache[$key] = $result;
    }

    public function hasColumn(string $table, string $column): bool
    {
        $this->assertIdentifier($column);
        return array_key_exists($column, $this->columns($table));
    }

    /**
     * Lee las columnas de varias tablas en una sola consulta.
     *
     * @param list<string> $tables
     * @return array<string,array<string,true>>
     */
    public function columnNamesForTables(array $tables): array
    {
        $tables = array_values(array_unique(array_map('strval', $tables)));
        if ($tables === []) {
            return [];
        }

        $result = [];
        $conditions = [];
        $params = ['schema' => $this->database()];
        foreach ($tables as $index => $table) {
            $this->assertIdentifier($table);
            $result[$table] = [];
            $parameter = 'table_' . $index;
            $conditions[] = 'BINARY TABLE_NAME=BINARY :' . $parameter;
            $params[$parameter] = $table;
        }

        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME,COLUMN_NAME AS Field,DATA_TYPE AS Type,
                    IS_NULLABLE AS `Null`,COLUMN_KEY AS `Key`,
                    COLUMN_DEFAULT AS `Default`,EXTRA AS Extra,
                    COLUMN_TYPE AS ColumnType
             FROM information_schema.COLUMNS
             WHERE BINARY TABLE_SCHEMA=BINARY :schema
               AND (' . implode(' OR ', $conditions) . ')
             ORDER BY TABLE_NAME,ORDINAL_POSITION'
        );
        $stmt->execute($params);
        $metadata = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) ($row['TABLE_NAME'] ?? '');
            $column = (string) ($row['Field'] ?? '');
            if ($table !== '' && $column !== '' && array_key_exists($table, $result)) {
                $result[$table][$column] = true;
                $metadata[$table][$column] = $row;
            }
        }
        foreach ($result as $table => $columns) {
            $this->cache['table:' . $table] = $columns !== [];
            $this->cache['columns:' . $table] = $metadata[$table] ?? [];
        }
        return $result;
    }

    public function columnExtra(string $table, string $column): ?string
    {
        $columns = $this->columns($table);
        return isset($columns[$column]) ? (string) ($columns[$column]['Extra'] ?? '') : null;
    }

    /** @return list<string> */
    public function indexes(string $table): array
    {
        $this->assertIdentifier($table);
        $key = 'indexes:' . $table;
        if (isset($this->cache[$key]) && is_array($this->cache[$key])) {
            return $this->cache[$key];
        }
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE BINARY TABLE_SCHEMA=BINARY :schema
               AND BINARY TABLE_NAME=BINARY :table_name
             ORDER BY INDEX_NAME'
        );
        $stmt->execute(['schema' => $this->database(), 'table_name' => $table]);
        return $this->cache[$key] = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * @param list<string> $tables
     * @return array<string,list<string>>
     */
    public function indexesForTables(array $tables): array
    {
        $tables = array_values(array_unique(array_map('strval', $tables)));
        if ($tables === []) {
            return [];
        }
        $result = [];
        $conditions = [];
        $params = ['schema' => $this->database()];
        foreach ($tables as $index => $table) {
            $this->assertIdentifier($table);
            $result[$table] = [];
            $parameter = 'table_' . $index;
            $conditions[] = 'BINARY TABLE_NAME=BINARY :' . $parameter;
            $params[$parameter] = $table;
        }
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT TABLE_NAME,INDEX_NAME
             FROM information_schema.STATISTICS
             WHERE BINARY TABLE_SCHEMA=BINARY :schema
               AND (' . implode(' OR ', $conditions) . ')
             ORDER BY TABLE_NAME,INDEX_NAME'
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) ($row['TABLE_NAME'] ?? '');
            $index = (string) ($row['INDEX_NAME'] ?? '');
            if ($table !== '' && $index !== '' && array_key_exists($table, $result)) {
                $result[$table][] = $index;
            }
        }
        foreach ($result as $table => $indexes) {
            $this->cache['indexes:' . $table] = $indexes;
        }
        return $result;
    }

    public function hasIndex(string $table, string $index): bool
    {
        $this->assertIdentifier($index);
        return in_array($index, $this->indexes($table), true);
    }

    /**
     * @return array<string,array{
     *   engine:string,collation:string,
     *   columns:array<string,array<string,mixed>>,
     *   indexes:array<string,array{unique:bool,columns:list<string>}>
     * }>
     */
    public function schemaSnapshot(): array
    {
        $key = 'snapshot';
        if (isset($this->cache[$key]) && is_array($this->cache[$key])) {
            return $this->cache[$key];
        }
        $tables = [];
        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME,ENGINE,TABLE_COLLATION
             FROM information_schema.TABLES
             WHERE BINARY TABLE_SCHEMA=BINARY :schema
             ORDER BY TABLE_NAME'
        );
        $stmt->execute(['schema' => $this->database()]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $name = (string) $row['TABLE_NAME'];
            $tables[$name] = [
                'engine' => (string) ($row['ENGINE'] ?? ''),
                'collation' => (string) ($row['TABLE_COLLATION'] ?? ''),
                'columns' => [],
                'indexes' => [],
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA
             FROM information_schema.COLUMNS
             WHERE BINARY TABLE_SCHEMA=BINARY :schema
             ORDER BY TABLE_NAME,ORDINAL_POSITION'
        );
        $stmt->execute(['schema' => $this->database()]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['TABLE_NAME'];
            if (!isset($tables[$table])) {
                continue;
            }
            $tables[$table]['columns'][(string) $row['COLUMN_NAME']] = [
                'type' => strtolower((string) $row['COLUMN_TYPE']),
                'nullable' => (string) $row['IS_NULLABLE'],
                'default' => $row['COLUMN_DEFAULT'],
                'extra' => strtolower((string) $row['EXTRA']),
            ];
        }

        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE BINARY TABLE_SCHEMA=BINARY :schema
             ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX'
        );
        $stmt->execute(['schema' => $this->database()]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) $row['TABLE_NAME'];
            if (!isset($tables[$table])) {
                continue;
            }
            $index = (string) $row['INDEX_NAME'];
            $tables[$table]['indexes'][$index] ??= [
                'unique' => (int) $row['NON_UNIQUE'] === 0,
                'columns' => [],
            ];
            $tables[$table]['indexes'][$index]['columns'][] = (string) $row['COLUMN_NAME'];
        }
        return $this->cache[$key] = $tables;
    }

    /** @return array<string,string> */
    public function tableCollations(array $tables): array
    {
        $tables = array_values(array_unique(array_map('strval', $tables)));
        if ($tables === []) {
            return [];
        }
        $result = [];
        $conditions = [];
        $params = ['schema' => $this->database()];
        foreach ($tables as $index => $table) {
            $this->assertIdentifier($table);
            $parameter = 'table_' . $index;
            $conditions[] = 'BINARY TABLE_NAME=BINARY :' . $parameter;
            $params[$parameter] = $table;
        }
        $stmt = $this->pdo->prepare(
            'SELECT TABLE_NAME,TABLE_COLLATION
             FROM information_schema.TABLES
             WHERE BINARY TABLE_SCHEMA=BINARY :schema
               AND (' . implode(' OR ', $conditions) . ')'
        );
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $table = (string) ($row['TABLE_NAME'] ?? '');
            if ($table !== '') {
                $result[$table] = (string) ($row['TABLE_COLLATION'] ?? '');
            }
        }
        return $result;
    }

    public function clear(): void
    {
        $this->cache = [];
        $this->database = null;
    }

    private function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
            throw new RuntimeException('Identificador de esquema inválido.');
        }
    }
}
