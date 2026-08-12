<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;

final class QueueV4CleanDatabaseContract
{
    private const CONTRACT = 'resources/release/queue-v4-canonical-db-contract-2.37.2.json';
    private int $metadataQueryCount = 0;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> */
    public function issues(): array
    {
        $this->metadataQueryCount = 0;
        $contract = $this->load();
        $expectedTables = $contract['tables'];
        $tableNames = array_keys($expectedTables);
        if ($tableNames === [] || array_filter($tableNames, 'is_string') !== $tableNames) {
            throw new RuntimeException('queue_v4_clean_db_contract_invalid');
        }

        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $issues = [];
        $server = (string) $this->pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        $engine = str_contains(strtolower($server), 'mariadb') ? 'MariaDB' : 'MySQL';
        preg_match('/^(\d+\.\d+\.\d+)/', $server, $versionMatch);
        if (!hash_equals((string) ($contract['database_engine'] ?? ''), $engine)) {
            $issues[] = 'db_contract_server_engine_invalid';
        }
        if (!hash_equals((string) ($contract['database_version_family'] ?? ''), (string) ($versionMatch[1] ?? ''))) {
            $issues[] = 'db_contract_server_version_invalid';
        }

        $placeholders = implode(',', array_fill(0, count($tableNames), '?'));
        $parameters = array_merge([$database], $tableNames);
        $tables = $this->group(
            $this->metadata(
                'SELECT TABLE_SCHEMA,TABLE_NAME,ENGINE,TABLE_COLLATION
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA=? AND TABLE_NAME IN (' . $placeholders . ')
                   AND TABLE_TYPE="BASE TABLE"
                 ORDER BY TABLE_NAME',
                $parameters
            ),
            'TABLE_NAME',
            $database
        );
        $columns = $this->group(
            $this->metadata(
                'SELECT TABLE_SCHEMA,TABLE_NAME,ORDINAL_POSITION,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,
                        COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME
                 FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=? AND TABLE_NAME IN (' . $placeholders . ')
                 ORDER BY TABLE_NAME,ORDINAL_POSITION',
                $parameters
            ),
            'TABLE_NAME',
            $database
        );
        $indexes = $this->group(
            $this->metadata(
                'SELECT TABLE_SCHEMA,TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,COLLATION,INDEX_TYPE
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA=? AND TABLE_NAME IN (' . $placeholders . ')
                 ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',
                $parameters
            ),
            'TABLE_NAME',
            $database
        );
        $foreignKeys = $this->group(
            $this->metadata(
                'SELECT k.CONSTRAINT_SCHEMA AS TABLE_SCHEMA,k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,
                        k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,
                        r.MATCH_OPTION,r.UPDATE_RULE,r.DELETE_RULE
                 FROM information_schema.KEY_COLUMN_USAGE k
                 INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                   ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
                  AND r.TABLE_NAME=k.TABLE_NAME
                  AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
                 WHERE k.CONSTRAINT_SCHEMA=? AND k.TABLE_NAME IN (' . $placeholders . ')
                   AND k.REFERENCED_TABLE_NAME IS NOT NULL
                 ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION',
                $parameters
            ),
            'TABLE_NAME',
            $database
        );

        foreach ($expectedTables as $table => $expected) {
            if (!is_string($table) || !is_array($expected)) {
                $issues[] = 'db_contract_document_invalid';
                continue;
            }
            $observedTableRows = $tables[$table] ?? [];
            if (count($observedTableRows) !== 1) {
                $issues[] = 'db_contract_table_missing:' . $table;
                continue;
            }
            $observedTable = $observedTableRows[0];
            if ((string) ($observedTable['ENGINE'] ?? '') !== (string) ($expected['engine'] ?? '')) {
                $issues[] = 'db_contract_engine_invalid:' . $table;
            }
            if ((string) ($observedTable['TABLE_COLLATION'] ?? '') !== (string) ($expected['collation'] ?? '')) {
                $issues[] = 'db_contract_collation_invalid:' . $table;
            }
            foreach ([
                'columns' => $columns[$table] ?? [],
                'indexes' => $indexes[$table] ?? [],
                'foreign_keys' => $foreignKeys[$table] ?? [],
            ] as $part => $observed) {
                if ($this->normalize($observed) !== $this->normalize($expected[$part] ?? [])) {
                    $issues[] = 'db_contract_' . $part . '_invalid:' . $table;
                }
            }
        }
        return array_values(array_unique($issues));
    }

    public function metadataQueryCount(): int
    {
        return $this->metadataQueryCount;
    }

    /** @param list<mixed> $parameters @return list<array<string,mixed>> */
    private function metadata(string $sql, array $parameters): array
    {
        $this->metadataQueryCount++;
        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<array<string,mixed>> $rows @return array<string,list<array<string,mixed>>> */
    private function group(array $rows, string $key, string $database): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $schema = (string) ($row['TABLE_SCHEMA'] ?? '');
            unset($row['TABLE_SCHEMA']);
            if (!hash_equals($database, $schema)) {
                continue;
            }
            $name = (string) ($row[$key] ?? '');
            unset($row[$key]);
            if ($name !== '') {
                $grouped[$name][] = $row;
            }
        }
        return $grouped;
    }

    /** @return array<string,mixed> */
    private function load(): array
    {
        $path = dirname(__DIR__, 2) . '/' . self::CONTRACT;
        $document = json_decode((string) @file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($document) || !is_array($document['tables'] ?? null)) {
            throw new RuntimeException('queue_v4_clean_db_contract_missing');
        }
        return $document;
    }

    private function normalize(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = $this->normalize($child);
            }
            return $value;
        }
        return $value === null ? null : (string) $value;
    }
}
