<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Session;
use App\Core\View;
use Throwable;

final class AsyncSectionService
{
    public function releaseSession(): void
    {
        Session::closeReadOnly();
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,mixed> $meta
     */
    public function render(string $section, string $view, array $data, array $meta = [], ?float $startedAt = null): never
    {
        ob_start();
        View::render($view, $data, false);
        $html = (string) ob_get_clean();
        $this->json([
            'ok' => true,
            'section' => $section,
            'html' => $html,
            'meta' => $this->meta($meta, $startedAt),
        ]);
    }

    /** @param array<string,mixed> $meta */
    public function failure(string $section, Throwable $error, array $meta = [], int $status = 500): never
    {
        $reported = SafeErrorPresenter::report(
            $error,
            'No fue posible cargar esta sección. Puede reintentar sin perder los filtros.',
            ['section' => $section]
        );
        http_response_code(max(400, min(599, $status)));
        $this->json([
            'ok' => false,
            'section' => $section,
            'error' => $reported['message'],
            'reference' => $reported['reference'],
            'meta' => $this->meta($meta),
        ]);
    }

    /** @param array<string,mixed> $meta @return array<string,mixed> */
    private function meta(array $meta, ?float $startedAt = null): array
    {
        if ($startedAt !== null) {
            $meta['query_ms'] = (int) round((microtime(true) - $startedAt) * 1000);
        }
        $meta['generated_at'] = gmdate(DATE_ATOM);
        return $meta;
    }

    /** @param array<string,mixed> $payload */
    private function json(array $payload): never
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
}
