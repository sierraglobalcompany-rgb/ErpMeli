<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use RuntimeException;

/**
 * Preserva evidencia creada por versiones antiguas alrededor de migraciones
 * históricas que ya fueron publicadas y, por tanto, no pueden reescribirse.
 */
final class HistoricalMigrationDataPreserver
{
    private const RETENTION_TABLE = 'system_retention_runs';
    private const RETENTION_HOLD = 'system_retention_runs_preserved_2265';

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function before(string $version): void
    {
        if (!str_starts_with($version, '146_')) {
            return;
        }
        if (!$this->tableExists(self::RETENTION_TABLE) || $this->tableExists(self::RETENTION_HOLD)) {
            return;
        }

        $this->pdo->exec(
            'RENAME TABLE `' . self::RETENTION_TABLE . '` TO `' . self::RETENTION_HOLD . '`'
        );
    }

    public function after(string $version): void
    {
        if (!str_starts_with($version, '153_')
            || !$this->tableExists(self::RETENTION_HOLD)
            || !$this->tableExists(self::RETENTION_TABLE)
        ) {
            return;
        }

        $sourceColumns = $this->columns(self::RETENTION_HOLD);
        $targetColumns = array_flip($this->columns(self::RETENTION_TABLE));
        $common = array_values(array_filter(
            $sourceColumns,
            static fn (string $column): bool => isset($targetColumns[$column])
        ));
        if ($common === []) {
            throw new RuntimeException('No fue posible conservar el historial técnico de retención.');
        }

        $quoted = implode(',', array_map(
            static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`',
            $common
        ));
        $this->pdo->exec(
            'INSERT IGNORE INTO `' . self::RETENTION_TABLE . '` (' . $quoted . ') '
            . 'SELECT ' . $quoted . ' FROM `' . self::RETENTION_HOLD . '`'
        );

        if (in_array('id', $common, true)) {
            $missing = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM `' . self::RETENTION_HOLD . '` legacy '
                . 'LEFT JOIN `' . self::RETENTION_TABLE . '` current_row '
                . 'ON current_row.id=legacy.id WHERE current_row.id IS NULL'
            )->fetchColumn();
            if ($missing > 0) {
                throw new RuntimeException('La actualización no pudo verificar todo el historial técnico conservado.');
            }
        } else {
            $sourceCount = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM `' . self::RETENTION_HOLD . '`'
            )->fetchColumn();
            $targetCount = (int) $this->pdo->query(
                'SELECT COUNT(*) FROM `' . self::RETENTION_TABLE . '`'
            )->fetchColumn();
            if ($targetCount < $sourceCount) {
                throw new RuntimeException('La actualización no pudo verificar el historial técnico conservado.');
            }
        }

        $this->pdo->exec('DROP TABLE `' . self::RETENTION_HOLD . '`');
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE BINARY TABLE_SCHEMA=BINARY DATABASE() AND BINARY TABLE_NAME=BINARY :table'
        );
        $stmt->execute(['table' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
            . 'WHERE BINARY TABLE_SCHEMA=BINARY DATABASE() AND BINARY TABLE_NAME=BINARY :table '
            . 'ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute(['table' => $table]);
        return array_values(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)));
    }
}
