<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;

final class QueueV4CleanDatabaseContract
{
    private const CONTRACT = 'resources/release/queue-v4-canonical-db-contract-2.37.1.json';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<string> */
    public function issues(): array
    {
        $contract = $this->load();
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
        foreach ($contract['tables'] as $table => $expected) {
            if (!is_string($table) || !is_array($expected)) {
                $issues[] = 'db_contract_document_invalid';
                continue;
            }
            $tableStatement = $this->pdo->prepare(
                'SELECT ENGINE,TABLE_COLLATION FROM information_schema.TABLES
                 WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ?
                   AND TABLE_TYPE="BASE TABLE"'
            );
            $tableStatement->execute([$database, $table]);
            $observedTable = $tableStatement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($observedTable)) {
                $issues[] = 'db_contract_table_missing:' . $table;
                continue;
            }
            if ((string) $observedTable['ENGINE'] !== (string) ($expected['engine'] ?? '')) {
                $issues[] = 'db_contract_engine_invalid:' . $table;
            }
            if ((string) $observedTable['TABLE_COLLATION'] !== (string) ($expected['collation'] ?? '')) {
                $issues[] = 'db_contract_collation_invalid:' . $table;
            }
            foreach ([
                'columns' => $this->columns($database, $table),
                'indexes' => $this->indexes($database, $table),
                'foreign_keys' => $this->foreignKeys($database, $table),
            ] as $part => $observed) {
                if ($this->normalize($observed) !== $this->normalize($expected[$part] ?? [])) {
                    $issues[] = 'db_contract_' . $part . '_invalid:' . $table;
                }
            }
        }
        return array_values(array_unique($issues));
    }

    /** @return list<array<string,mixed>> */
    private function columns(string $database, string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT ORDINAL_POSITION,COLUMN_NAME,DATA_TYPE,COLUMN_TYPE,IS_NULLABLE,
                    COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME
             FROM information_schema.COLUMNS
             WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ?
             ORDER BY ORDINAL_POSITION'
        );
        $statement->execute([$database, $table]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function indexes(string $database, string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,COLLATION,INDEX_TYPE
             FROM information_schema.STATISTICS
             WHERE BINARY TABLE_SCHEMA=BINARY ? AND BINARY TABLE_NAME=BINARY ?
             ORDER BY INDEX_NAME,SEQ_IN_INDEX'
        );
        $statement->execute([$database, $table]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return list<array<string,mixed>> */
    private function foreignKeys(string $database, string $table): array
    {
        $statement = $this->pdo->prepare(
            'SELECT k.CONSTRAINT_NAME,k.ORDINAL_POSITION,k.COLUMN_NAME,
                    k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,
                    r.MATCH_OPTION,r.UPDATE_RULE,r.DELETE_RULE
             FROM information_schema.KEY_COLUMN_USAGE k
             INNER JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA
              AND r.TABLE_NAME=k.TABLE_NAME
              AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME
             WHERE BINARY k.CONSTRAINT_SCHEMA=BINARY ? AND BINARY k.TABLE_NAME=BINARY ?
               AND k.REFERENCED_TABLE_NAME IS NOT NULL
             ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION'
        );
        $statement->execute([$database, $table]);
        return $statement->fetchAll(PDO::FETCH_ASSOC);
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
