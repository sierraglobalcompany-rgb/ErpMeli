<?php

declare(strict_types=1);

namespace App\Services;

final class ExportService
{
    public function streamCsv(string $filename, array $rows): never
    {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'wb');
        if ($rows !== []) {
            fputcsv($out, array_keys($rows[0]), ',', '"', '', "\n");
            foreach ($rows as $row) {
                fputcsv($out, $row, ',', '"', '', "\n");
            }
        }
        fclose($out);
        exit;
    }

    public function streamExcelHtml(string $filename, array $rows, string $title): never
    {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        echo "\xEF\xBB\xBF";
        echo '<html><meta charset="UTF-8"><body><h1>' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1><table border="1">';
        if ($rows !== []) {
            echo '<tr>';
            foreach (array_keys($rows[0]) as $key) {
                echo '<th>' . htmlspecialchars((string) $key, ENT_QUOTES, 'UTF-8') . '</th>';
            }
            echo '</tr>';
            foreach ($rows as $row) {
                echo '<tr>';
                foreach ($row as $value) {
                    echo '<td>' . htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8') . '</td>';
                }
                echo '</tr>';
            }
        }
        echo '</table></body></html>';
        exit;
    }
}
