<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\QueryPerformanceCollector;
use App\Core\AppPaths;
use App\Core\Database;
use App\ValueObjects\QueryTrace;
use PDO;
use Throwable;

/**
 * Perfilador opt-in para diagnóstico local.
 *
 * Solo conserva contadores agregados de la sesión MariaDB. Nunca registra SQL,
 * parámetros, nombres de cuenta ni datos comerciales.
 */
final class FileQueryPerformanceCollector implements QueryPerformanceCollector
{
    private const MAX_BYTES = 1048576;

    /** @var list<string> */
    private const COUNTERS = [
        'Questions',
        'Rows_read',
        'Created_tmp_tables',
        'Created_tmp_disk_tables',
        'Sort_rows',
    ];

    public function begin(string $route): QueryTrace
    {
        $path = (string) (parse_url($route, PHP_URL_PATH) ?: '/');
        $path = preg_replace('/\/\d+(?=\/|$)/', '/:id', $path) ?: '/';
        $path = preg_replace('/[^A-Za-z0-9_\/.:-]/', '', $path) ?: '/';
        return new QueryTrace(
            hash('sha256', $path),
            mb_substr($path, 0, 160),
            microtime(true),
            $this->sessionCounters()
        );
    }

    public function finish(QueryTrace $trace): void
    {
        $after = $this->sessionCounters();
        $delta = [];
        foreach (self::COUNTERS as $counter) {
            $delta[$counter] = max(
                0,
                (int) ($after[$counter] ?? 0) - (int) ($trace->sessionCounters[$counter] ?? 0)
            );
        }
        $record = [
            'at' => gmdate(DATE_ATOM),
            'route_key' => $trace->routeKey,
            'route_hash' => $trace->routeHash,
            'duration_ms' => (int) round((microtime(true) - $trace->startedAt) * 1000),
            'statements' => $delta['Questions'],
            'rows_read' => $delta['Rows_read'],
            'temporary_tables' => $delta['Created_tmp_tables'],
            'disk_temporary_tables' => $delta['Created_tmp_disk_tables'],
            'filesort_rows' => $delta['Sort_rows'],
        ];
        $this->append($record);
    }

    /** @return array<string,int> */
    private function sessionCounters(): array
    {
        try {
            $quoted = implode(',', array_map(
                static fn (string $name): string => Database::connection()->quote($name),
                self::COUNTERS
            ));
            $rows = Database::connection()->query(
                'SHOW SESSION STATUS WHERE Variable_name IN (' . $quoted . ')'
            )->fetchAll(PDO::FETCH_KEY_PAIR);
            $result = [];
            foreach (self::COUNTERS as $counter) {
                $result[$counter] = (int) ($rows[$counter] ?? 0);
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }

    /** @param array<string,mixed> $record */
    private function append(array $record): void
    {
        $directory = AppPaths::storage('logs');
        if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
            return;
        }
        $handle = @fopen($directory . '/query-profile.jsonl', 'c+b');
        if ($handle === false || !@flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return;
        }
        try {
            $stats = @fstat($handle);
            $size = $stats === false ? 0 : (int) $stats['size'];
            if ($size >= self::MAX_BYTES) {
                @ftruncate($handle, 0);
                @rewind($handle);
            } else {
                @fseek($handle, 0, SEEK_END);
            }
            @fwrite(
                $handle,
                (json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}') . PHP_EOL
            );
            @fflush($handle);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
}
