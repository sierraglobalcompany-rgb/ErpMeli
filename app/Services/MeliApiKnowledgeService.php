<?php

declare(strict_types=1);

namespace App\Services;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class MeliApiKnowledgeService
{
    private const SOURCE_FILES = [
        'mercadolibre-api-master.md',
        'mercadolibre-api-snapshot.json',
        'mercadolibre-api-changelog.md',
        'mercadolibre-api-consulta.md',
        'mercadolibre-api-coverage.md',
        'mercadolibre-api-index.json',
    ];

    private const SECRET_PATTERNS = [
        '/access[_-]?token\s*[:=]\s*[A-Za-z0-9._-]{20,}/i',
        '/refresh[_-]?token\s*[:=]\s*[A-Za-z0-9._-]{20,}/i',
        '/client[_-]?secret\s*[:=]\s*[A-Za-z0-9._-]{12,}/i',
        '/authorization\s*:\s*bearer\s+[A-Za-z0-9._-]+/i',
        '/DB_PASS\s*=\s*.+/i',
        '/MELI_CLIENT_SECRET\s*=\s*.+/i',
    ];

    /**
     * @return array<string,mixed>
     */
    public function generate(string $root, string $sourceDir): array
    {
        $root = rtrim(str_replace('\\', '/', $root), '/');
        $sourceDir = rtrim(str_replace('\\', '/', $sourceDir), '/');
        if (!is_dir($root . '/app') || !is_dir($root . '/bin')) {
            throw new RuntimeException('La ruta del ERP no parece válida: ' . $root);
        }
        if (!is_dir($sourceDir)) {
            throw new RuntimeException('No existe la carpeta de documentación Mercado Libre: ' . $sourceDir);
        }

        $resources = $root . '/resources/mercadolibre-api';
        $generated = $resources . '/generated';
        $sourceCopy = $resources . '/source';
        $this->ensureDirectory($generated);
        $this->ensureDirectory($sourceCopy);

        $sourceSummary = $this->copySanitizedSources($sourceDir, $sourceCopy);
        $docs = $this->mergeVerifiedProjectContracts($this->loadDocumentation($sourceDir), $root);
        $registered = $this->registeredEndpoints($root);
        $usage = $this->scanErpUsage($root);
        $fields = $this->fieldContracts();
        $endpointContracts = $this->endpointContracts($docs, $registered, $usage);
        $coverage = $this->coverage($docs, $endpointContracts, $usage, $fields);
        $risks = $this->risks($endpointContracts, $fields, $usage);

        $this->writeJson($generated . '/endpoints.json', $endpointContracts);
        $this->writeJson($generated . '/fields.json', $fields);
        $this->writeJson($generated . '/erp-usage.json', $usage);
        $this->writeJson($generated . '/coverage.json', $coverage);
        $this->writeJson($generated . '/risks.json', $risks);

        $version = trim((string) @file_get_contents($root . '/VERSION')) ?: 'desconocida';
        $suffix = preg_replace('/[^0-9.]/', '', $version) ?: '2.10.0';
        $reports = [
            'AUDITORIA_API_MERCADO_LIBRE_USO_REAL_ERP_MELI_' . $suffix . '.md' => $this->renderMainAudit($version, $docs, $endpointContracts, $fields, $coverage, $risks, $sourceSummary),
            'MATRIZ_ENDPOINTS_ML_ERP_MELI_' . $suffix . '.md' => $this->renderEndpointMatrix($version, $endpointContracts),
            'MATRIZ_VARIABLES_API_ML_ERP_MELI_' . $suffix . '.md' => $this->renderFieldMatrix($version, $fields),
            'RIESGOS_OPTIMIZACIONES_API_ML_ERP_MELI_' . $suffix . '.md' => $this->renderRisks($version, $risks),
            'CHECKLIST_CORRECCION_API_ML_ERP_MELI_' . $suffix . '.md' => $this->renderChecklist($version, $risks, $endpointContracts),
        ];
        foreach ($reports as $file => $content) {
            file_put_contents($root . '/' . $file, $content);
        }

        return [
            'version' => $version,
            'root' => $root,
            'source_dir' => $sourceDir,
            'resources_dir' => $resources,
            'generated_files' => array_keys($reports),
            'json_files' => [
                'resources/mercadolibre-api/generated/endpoints.json',
                'resources/mercadolibre-api/generated/fields.json',
                'resources/mercadolibre-api/generated/erp-usage.json',
                'resources/mercadolibre-api/generated/coverage.json',
                'resources/mercadolibre-api/generated/risks.json',
                'resources/mercadolibre-api/generated/blocking-rules.json',
            ],
            'endpoint_count' => count($endpointContracts),
            'field_count' => count($fields),
            'risk_count' => count($risks),
            'source_summary' => $sourceSummary,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function queryEndpoint(string $root, string $path): array
    {
        $file = rtrim(str_replace('\\', '/', $root), '/') . '/resources/mercadolibre-api/generated/endpoints.json';
        if (!is_file($file)) {
            return [];
        }
        $rows = json_decode((string) file_get_contents($file), true);
        if (!is_array($rows)) {
            return [];
        }
        $needle = $this->normalizeEndpointPath($path);
        foreach ($rows as $row) {
            if (($row['normalized_path'] ?? '') === $needle || ($row['documented_path'] ?? '') === $path) {
                return is_array($row) ? $row : [];
            }
        }
        return [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function allEndpoints(string $root): array
    {
        $file = rtrim(str_replace('\\', '/', $root), '/') . '/resources/mercadolibre-api/generated/endpoints.json';
        if (!is_file($file)) {
            return [];
        }
        $rows = json_decode((string) file_get_contents($file), true);
        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function copySanitizedSources(string $sourceDir, string $targetDir): array
    {
        $summary = ['copied' => 0, 'missing' => [], 'secrets_detected' => 0, 'files' => []];
        foreach (self::SOURCE_FILES as $file) {
            $source = $sourceDir . '/' . $file;
            if (!is_file($source)) {
                $summary['missing'][] = $file;
                continue;
            }
            $content = (string) file_get_contents($source);
            $secretHits = 0;
            foreach (self::SECRET_PATTERNS as $pattern) {
                $content = preg_replace_callback($pattern, static function () use (&$secretHits): string {
                    $secretHits++;
                    return 'SECRETO DETECTADO — NO INCLUIDO';
                }, $content) ?? $content;
            }
            file_put_contents($targetDir . '/' . $file, $content);
            $summary['copied']++;
            $summary['secrets_detected'] += $secretHits;
            $summary['files'][] = [
                'name' => $file,
                'bytes' => strlen($content),
                'sha256' => hash('sha256', $content),
                'secret_hits' => $secretHits,
            ];
        }
        return $summary;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadDocumentation(string $sourceDir): array
    {
        $snapshotPath = $sourceDir . '/mercadolibre-api-snapshot.json';
        $indexPath = $sourceDir . '/mercadolibre-api-index.json';
        $coveragePath = $sourceDir . '/mercadolibre-api-coverage.md';
        $masterPath = $sourceDir . '/mercadolibre-api-master.md';

        $snapshot = is_file($snapshotPath) ? json_decode((string) file_get_contents($snapshotPath), true) : [];
        $index = is_file($indexPath) ? json_decode((string) file_get_contents($indexPath), true) : [];
        $coverage = is_file($coveragePath) ? (string) file_get_contents($coveragePath) : '';
        $master = is_file($masterPath) ? (string) file_get_contents($masterPath) : '';

        $endpoints = [];
        foreach (($snapshot['pages'] ?? []) as $page) {
            if (!is_array($page)) {
                continue;
            }
            foreach (($page['endpoints'] ?? []) as $endpoint) {
                if (!is_array($endpoint)) {
                    continue;
                }
                $path = (string) ($endpoint['path'] ?? '');
                if ($path === '' || str_starts_with($path, 'auth:')) {
                    continue;
                }
                $method = strtoupper((string) ($endpoint['method'] ?? 'UNKNOWN'));
                $normalized = $this->normalizeEndpointPath($path);
                $key = $method . ' ' . $normalized;
                if (!isset($endpoints[$key])) {
                    $endpoints[$key] = [
                        'method' => $method,
                        'documented_path' => $path,
                        'normalized_path' => $normalized,
                        'source_pages' => [],
                        'modules' => [],
                    ];
                }
                $endpoints[$key]['source_pages'][] = [
                    'title' => (string) ($page['title'] ?? ''),
                    'url' => (string) ($page['url'] ?? ''),
                ];
                $module = (string) ($page['module'] ?? '');
                if ($module !== '' && !in_array($module, $endpoints[$key]['modules'], true)) {
                    $endpoints[$key]['modules'][] = $module;
                }
            }
        }

        return [
            'generated_at' => (string) ($snapshot['generated_at'] ?? $index['generated_at'] ?? ''),
            'cutoff_date' => (string) ($snapshot['cutoff_date'] ?? $index['cutoff_date'] ?? ''),
            'source_root' => (string) ($snapshot['source_root'] ?? $index['source_root'] ?? ''),
            'api_docs_root' => (string) ($snapshot['api_docs_root'] ?? $index['api_docs_root'] ?? ''),
            'page_count' => (int) ($snapshot['page_count'] ?? $index['page_count'] ?? 0),
            'endpoint_count' => (int) ($snapshot['endpoint_count'] ?? $index['endpoint_count'] ?? count($endpoints)),
            'endpoints' => array_values($endpoints),
            'module_summary' => $index['modules'] ?? [],
            'coverage_text' => $coverage,
            'master_text' => $master,
        ];
    }

    /**
     * Incorpora únicamente contratos que el proyecto documentó con endpoint y fuente oficial explícitos.
     *
     * @param array<string,mixed> $docs
     * @return array<string,mixed>
     */
    private function mergeVerifiedProjectContracts(array $docs, string $root): array
    {
        $map = $root . '/docs/mercadolibre_api_map.md';
        if (!is_file($map)) {
            return $docs;
        }
        $rows = is_array($docs['endpoints'] ?? null) ? $docs['endpoints'] : [];
        $known = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $known[strtoupper((string) ($row['method'] ?? 'GET')) . ' ' . (string) ($row['normalized_path'] ?? '')] = true;
            }
        }
        foreach (preg_split('/\R/', (string) file_get_contents($map)) ?: [] as $line) {
            if (
                preg_match('~^\|[^|]+\|\s*`(?<endpoint>/[^`]+)`\s*\|[^|]*\|\s*(?<url>https://developers\.mercadolibre\.com[^|\s]+)\s*\|~u', $line, $match) !== 1
            ) {
                continue;
            }
            $endpoint = trim((string) $match['endpoint']);
            $normalized = $this->normalizeEndpointPath($endpoint);
            $key = 'GET ' . $normalized;
            if (isset($known[$key])) {
                continue;
            }
            $rows[] = [
                'method' => 'GET',
                'documented_path' => $endpoint,
                'normalized_path' => $normalized,
                'source_pages' => [[
                    'title' => 'Contrato verificado del proyecto',
                    'url' => trim((string) $match['url']),
                ]],
                'modules' => ['Meli Growth'],
            ];
            $known[$key] = true;
        }
        $docs['endpoints'] = $rows;
        $docs['endpoint_count'] = count($rows);
        return $docs;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function registeredEndpoints(string $root): array
    {
        $file = $root . '/app/Services/MeliEndpointRegistry.php';
        if (!is_file($file)) {
            return [];
        }
        $content = (string) file_get_contents($file);
        preg_match_all(
            "/\\['pattern'\\s*=>\\s*'(?<pattern>~(?:\\\\.|[^~])*~[a-zA-Z]*)'\\s*,\\s*'status'\\s*=>\\s*'(?<status>[^']+)'/",
            $content,
            $matches
        );
        $rows = [];
        foreach ($matches['pattern'] as $index => $pattern) {
            $status = (string) ($matches['status'][$index] ?? 'investigating');
            $rows[] = [
                'method' => 'GET',
                'registry_pattern' => $pattern,
                'normalized_path' => $this->patternToEndpoint($pattern),
                'source_file' => 'app/Services/MeliEndpointRegistry.php',
                'status' => match ($status) {
                    'confirmed' => 'allowed_read',
                    'disabled' => 'disabled_by_policy',
                    default => $status,
                },
            ];
        }
        return $rows;
    }

    /**
     * @return array<string,mixed>
     */
    private function scanErpUsage(string $root): array
    {
        $phpFiles = $this->phpFiles($root, ['vendor', 'storage', 'resources/mercadolibre-api']);
        $calls = [];
        $publicCatalogApiHits = [];
        $allMeliReferences = [];
        foreach ($phpFiles as $file) {
            $relative = $this->relativePath($root, $file);
            $content = (string) file_get_contents($file);
            $lines = preg_split('/\R/', $content) ?: [];
            foreach ($lines as $index => $line) {
                if (str_contains($line, 'MeliApiClient')) {
                    $allMeliReferences[] = ['file' => $relative, 'line' => $index + 1, 'text' => trim($line)];
                    if (str_contains($relative, 'Views/public_catalog') || str_contains($relative, 'PublicCatalogController')) {
                        $publicCatalogApiHits[] = ['file' => $relative, 'line' => $index + 1, 'text' => trim($line)];
                    }
                }
                if (
                    !str_contains($line, '$this->api->get(')
                    && !str_contains($line, '$api->get(')
                    && !str_contains($line, 'MeliApiClient')
                ) {
                    continue;
                }
                if (preg_match_all('/->get\(\s*([^\n;]+)\)/', $line, $matches)) {
                    foreach ($matches[1] as $expression) {
                        if (!str_contains($expression, "'/") && !str_contains($expression, '"/')) {
                            continue;
                        }
                        $calls[] = [
                            'method' => 'GET',
                            'path_expression' => trim($expression),
                            'normalized_path' => $this->expressionToEndpoint($expression),
                            'file' => $relative,
                            'line' => $index + 1,
                            'module' => $this->moduleForFile($relative),
                            'context' => $this->contextForFile($relative),
                        ];
                    }
                }
            }
        }

        return [
            'generated_at' => gmdate('c'),
            'api_calls' => $this->dedupeUsage($calls),
            'meli_references' => $allMeliReferences,
            'public_catalog_api_hits' => $publicCatalogApiHits,
            'scanned_php_files' => count($phpFiles),
        ];
    }

    /**
     * @param array<string,mixed> $docs
     * @param list<array<string,mixed>> $registered
     * @param array<string,mixed> $usage
     * @return list<array<string,mixed>>
     */
    private function endpointContracts(array $docs, array $registered, array $usage): array
    {
        $documented = [];
        foreach (($docs['endpoints'] ?? []) as $endpoint) {
            if (!is_array($endpoint)) {
                continue;
            }
            $documented[(string) $endpoint['normalized_path']][] = $endpoint;
        }
        $registeredByPath = [];
        foreach ($registered as $row) {
            $registeredByPath[(string) $row['normalized_path']][] = $row;
        }
        $usedByPath = [];
        foreach (($usage['api_calls'] ?? []) as $call) {
            if (!is_array($call)) {
                continue;
            }
            $usedByPath[(string) $call['normalized_path']][] = $call;
        }

        $paths = array_values(array_unique(array_merge(array_keys($documented), array_keys($registeredByPath), array_keys($usedByPath))));
        sort($paths);
        $contracts = [];
        foreach ($paths as $path) {
            if ($path === '/' || $path === '') {
                continue;
            }
            $docRows = $documented[$path] ?? [];
            $regRows = $registeredByPath[$path] ?? [];
            $useRows = $usedByPath[$path] ?? [];
            $status = $this->endpointStatus($path, $docRows, $regRows, $useRows);
            $contracts[] = [
                'method' => 'GET',
                'documented_path' => $docRows[0]['documented_path'] ?? $path,
                'normalized_path' => $path,
                'module_ml' => implode(', ', array_values(array_unique(array_merge(...array_map(static fn ($r): array => is_array($r['modules'] ?? null) ? $r['modules'] : [], $docRows ?: [['modules' => []]]))))),
                'source_pages' => $this->sourcePages($docRows),
                'erp_services' => array_values(array_unique(array_map(static fn ($r): string => (string) ($r['file'] ?? ''), $useRows))),
                'erp_modules' => array_values(array_unique(array_map(static fn ($r): string => (string) ($r['module'] ?? ''), $useRows))),
                'contexts' => array_values(array_unique(array_map(static fn ($r): string => (string) ($r['context'] ?? ''), $useRows))),
                'registry_patterns' => array_values(array_unique(array_map(static fn ($r): string => (string) ($r['registry_pattern'] ?? ''), $regRows))),
                'status' => $status,
                'allowed_contexts' => $this->allowedContexts($path, $status),
                'parameters' => $this->knownParameters($path),
                'response_fields' => $this->fieldsForEndpoint($path),
                'local_tables' => $this->tablesForEndpoint($path),
                'rate_limit_risk' => $this->rateRisk($path),
                'respects_retry_after' => true,
                'requires_seller_token' => !str_starts_with($path, '/oauth/'),
                'requires_scope_review' => in_array($path, ['/questions/search', '/post-purchase/v1/claims/search', '/billing/integration/group/ML/order/details', '/user-products/{user_product_id}/stock'], true),
                'recommendation' => $this->endpointRecommendation($path, $status),
            ];
        }
        return $contracts;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fieldContracts(): array
    {
        return [
            $this->field('/orders/search', 'id', 'string|int', 'meli_orders.external_order_id', 'OrderSyncService::persistOrder', 'Identificador externo de orden. Clave de idempotencia por cuenta.', ['auditoria', 'facturacion', 'detalle_orden']),
            $this->field('/orders/search', 'date_created', 'datetime ISO-8601', 'meli_orders.date_created / date_created_local', 'MeliDateTimeNormalizer', 'Guardar UTC y normalizado local para reportes; usar rangos half-open.', ['auditoria', 'facturacion', 'sync']),
            $this->field('/orders/search', 'date_closed', 'datetime ISO-8601|null', 'meli_orders.date_closed', 'MeliDateTimeNormalizer', 'Puede venir nulo; no usar como única fecha de auditoría.', ['auditoria']),
            $this->field('/orders/search', 'status', 'string', 'meli_orders.status', 'OrderSyncService::persistOrder', 'Afecta filtros y facturación; documentar exclusiones de canceladas.', ['ventas', 'auditoria']),
            $this->field('/orders/search', 'payments[]', 'array', 'meli_payments.*', 'OrderSyncService::persistPayment', 'Fuente principal de pagos; no usar /payments/{id} por defecto.', ['pagos', 'facturacion']),
            $this->field('/orders/search', 'shipping.id', 'string|int|null', 'meli_orders.external_shipping_id / meli_shipments.external_shipment_id', 'OrderSyncService::persistRelated', 'Permite obtener envío; puede venir dentro de pack.', ['envios', 'finanzas']),
            $this->field('/orders/search', 'pack_id', 'string|int|null', 'meli_orders.external_pack_id / meli_packs.external_pack_id', 'OrderSyncService::persistPack', 'Si existe, consultar pack para resolver shipment.', ['packs', 'envios']),
            $this->field('/orders/search', 'order_items[].item.id', 'string', 'meli_order_items.external_item_id', 'OrderSyncService::persistItems', 'Debe cruzar con publicaciones ML sin duplicar.', ['productos', 'facturacion']),
            $this->field('/orders/search', 'order_items[].item.title', 'string', 'meli_order_items.title', 'OrderSyncService::persistItems', 'Clave para buscador por producto.', ['ventas']),
            $this->field('/orders/search', 'order_items[].item.seller_sku', 'string|null', 'meli_order_items.seller_sku', 'OrderSyncService::persistItems', 'Puede faltar; complementar con publicaciones/atributos.', ['bodega', 'facturacion']),
            $this->field('/orders/search', 'order_items[].quantity', 'int', 'meli_order_items.quantity', 'OrderSyncService::persistItems', 'Usar entero para unidades.', ['facturacion']),
            $this->field('/orders/search', 'order_items[].unit_price', 'decimal', 'meli_order_items.unit_price', 'OrderSyncService::persistItems', 'Precio de venta por unidad.', ['facturacion']),
            $this->field('/orders/search', 'order_items[].sale_fee', 'decimal|null', 'meli_order_items.sale_fee', 'OrderSyncService::persistItems', 'Cargo/comisión local parcial; billing puede mejorar exactitud.', ['conciliacion']),
            $this->field('/users/{seller_id}/items/search', 'results[]', 'array<string>', 'meli_items.external_item_id', 'MeliItemSyncService::sync', 'Usa offset actualmente; evaluar scan/scroll_id para catálogos grandes.', ['productos_ml', 'catalogos']),
            $this->field('/items/{id}', 'id', 'string', 'meli_items.external_item_id', 'MeliItemSyncService::persistItem', 'Clave por cuenta + publicación.', ['catalogos']),
            $this->field('/items/{id}', 'title', 'string', 'meli_items.title / catalog_items.title_snapshot', 'MeliItemSyncService + CatalogSnapshotService', 'Snapshot para catálogo público.', ['catalogos', 'productos_ml']),
            $this->field('/items/{id}', 'price', 'decimal', 'meli_items.price / catalog_items.price_snapshot', 'MeliItemSyncService + CatalogSnapshotService', 'Puede cambiar; revisión requiere aprobación antes de aplicar.', ['catalogos']),
            $this->field('/items/{id}', 'base_price', 'decimal|null', 'meli_items.base_price', 'MeliItemSyncService::persistItem', 'Útil para comparar descuentos/precio base.', ['productos_ml']),
            $this->field('/items/{id}', 'original_price', 'decimal|null', 'meli_items.original_price', 'MeliItemSyncService::persistItem', 'Puede venir nulo.', ['productos_ml']),
            $this->field('/items/{id}', 'condition', 'string|null', 'meli_items.condition / catalog_items.condition_snapshot', 'MeliItemSyncService + CatalogSnapshotService', 'Filtro nuevo/usado en catálogo.', ['catalogos']),
            $this->field('/items/{id}', 'available_quantity', 'int', 'meli_items.available_quantity / catalog_items.stock_available', 'MeliItemSyncService + CatalogSnapshotService', 'Stock total si no hay stock multi-origen.', ['catalogos', 'inventario']),
            $this->field('/items/{id}', 'sold_quantity', 'int', 'meli_items.sold_quantity / catalog_items.sold_quantity', 'MeliItemSyncService + CatalogSnapshotService', 'Mostrar vendidos solo si catálogo lo permite.', ['catalogos']),
            $this->field('/items/{id}', 'status', 'string', 'meli_items.status / catalog_items.status', 'MeliItemSyncService + CatalogSnapshotService', 'Controla visibilidad pública configurable.', ['catalogos']),
            $this->field('/items/{id}', 'permalink', 'url|null', 'meli_items.permalink / catalog_items.permalink', 'MeliItemSyncService + CatalogSnapshotService', 'Link público opcional a ML.', ['catalogos']),
            $this->field('/items/{id}', 'thumbnail', 'url|null', 'meli_items.thumbnail / catalog_items.thumbnail_url', 'MeliItemSyncService + CatalogSnapshotService', 'Solo fallback; galería debe preferir meli_item_pictures.', ['catalogos']),
            $this->field('/items/{id}', 'pictures[]', 'array', 'meli_item_pictures.*', 'MeliItemSyncService::persistPictures', 'Usar secure_url/url y position; evitar miniaturas -I como principal si hay -O.', ['catalogos']),
            $this->field('/items/{id}', 'variations[]', 'array', 'meli_item_variations.*', 'MeliItemSyncService::persistVariations', 'Público consolida por publicación; privado puede expandir variaciones.', ['catalogos']),
            $this->field('/items/{id}', 'attributes[]', 'array', 'meli_item_attributes.*', 'MeliItemSyncService::persistAttributes', 'Usado para SKU y características públicas sanitizadas.', ['catalogos']),
            $this->field('/items/{id}', 'shipping.mode', 'string|null', 'meli_items.shipping_mode / catalog_items.shipping_methods_json', 'MeliItemSyncService + CatalogSnapshotService', 'No confundir logística principal con métodos disponibles.', ['catalogos', 'envios']),
            $this->field('/items/{id}', 'shipping.logistic_type', 'string|null', 'meli_items.logistic_type / catalog_items.shipping_methods_json', 'MeliItemSyncService + CatalogSnapshotService', 'fulfillment, self_service, cross_docking, xd_drop_off, drop_off.', ['catalogos', 'envios']),
            $this->field('/items/{id}', 'shipping.tags', 'array|null', 'meli_items.raw_json / catalog_items.shipping_methods_json', 'CatalogSnapshotService', 'Puede evidenciar Flex adicional; no inventar si no viene localmente.', ['catalogos']),
            $this->field('/items/{id}', 'user_product_id', 'string|null', 'meli_items.user_product_id / meli_item_variations.user_product_id', 'MeliItemSyncService::userProductId', 'Necesario para stock multi-origen.', ['stock', 'catalogos']),
            $this->field('/items/{id}/description', 'plain_text', 'string|null', 'meli_item_descriptions.plain_text', 'MeliItemDescriptionService', 'Cache local; catálogo público no consulta API.', ['catalogos']),
            $this->field('/categories/{id}', 'name', 'string', 'meli_categories.name / catalog_categories.visible_name', 'MeliCategoryService + CatalogSnapshotService', 'Evita mostrar MCO... como categoría pública.', ['catalogos']),
            $this->field('/user-products/{user_product_id}/stock', 'locations[]', 'array', 'meli_item_stock_locations.*', 'MeliItemStockService', 'Endpoint requiere validación de permisos; cacheado por admin/job.', ['catalogos', 'stock']),
            $this->field('/shipments/{id}', 'status', 'string', 'meli_shipments.status', 'OrderSyncService::persistShipment', 'Base de filtros de envíos.', ['envios']),
            $this->field('/shipments/{id}', 'substatus', 'string|null', 'meli_shipments.substatus', 'OrderSyncService::persistShipment', 'Importante para pendientes/listos.', ['envios']),
            $this->field('/shipments/{id}', 'logistic_type', 'string|null', 'meli_shipments.logistic_type', 'OrderSyncService::persistShipment', 'Full/Flex/Colecta en envíos reales.', ['envios']),
            $this->field('/shipments/{id}', 'shipping_option.cost', 'decimal|null', 'meli_shipments.buyer_cost/gross_cost', 'OrderSyncService::persistShipment', 'No siempre equivale al cargo vendedor financiero.', ['finanzas']),
            $this->field('/questions/search', 'questions[]', 'array', 'meli_questions.*', 'QuestionSyncService', 'Solo lectura; revisar api_version=4 y estado UNANSWERED.', ['preguntas']),
            $this->field('/post-purchase/v1/claims/search', 'data[]|claims[]', 'array', 'meli_claims.*', 'ClaimSyncService', 'Revisar filtros players.user_id/role según documentación.', ['reclamos']),
            $this->field('/billing/integration/group/ML/order/details', 'billing lines', 'array', 'meli_order_billing_details.* / meli_order_financials.*', 'OrderBillingImportService', 'Parser actual es heurístico; validar estructura oficial y clasificaciones.', ['conciliacion', 'facturacion']),
            $this->field('/missed_feeds', 'messages[]|results[]', 'array', 'webhook_events / internal_notifications', 'WebhookService', 'Recuperación de notificaciones perdidas.', ['notificaciones']),
        ];
    }

    /**
     * @param list<string> $consumers
     * @return array<string,mixed>
     */
    private function field(string $endpoint, string $remoteField, string $type, string $localTarget, string $service, string $recommendation, array $consumers): array
    {
        return [
            'endpoint' => $endpoint,
            'remote_field' => $remoteField,
            'expected_type' => $type,
            'nullable' => str_contains($type, 'null'),
            'example' => $this->fakeExample($remoteField, $type),
            'local_target' => $localTarget,
            'service' => $service,
            'normalizer' => $this->normalizerForField($remoteField),
            'timezone_rule' => str_contains($remoteField, 'date') || str_contains($remoteField, '_at') ? 'Guardar UTC; mostrar America/Bogota con DateTimePresenter.' : 'No aplica.',
            'data_kind' => $this->dataKindForField($remoteField),
            'affects' => $consumers,
            'contains_sensitive_data' => in_array($remoteField, ['buyer', 'buyer_id', 'payments[]'], true),
            'duplicate_risk' => $this->duplicateRiskForField($remoteField),
            'recommendation' => $recommendation,
        ];
    }

    /**
     * @param array<string,mixed> $docs
     * @param list<array<string,mixed>> $endpointContracts
     * @param array<string,mixed> $usage
     * @param list<array<string,mixed>> $fields
     * @return array<string,mixed>
     */
    private function coverage(array $docs, array $endpointContracts, array $usage, array $fields): array
    {
        $statuses = [];
        foreach ($endpointContracts as $endpoint) {
            $status = (string) ($endpoint['status'] ?? 'unknown');
            $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        }
        $fieldByModule = [];
        foreach ($fields as $field) {
            foreach (($field['affects'] ?? []) as $module) {
                $fieldByModule[(string) $module] = ($fieldByModule[(string) $module] ?? 0) + 1;
            }
        }
        return [
            'generated_at' => gmdate('c'),
            'docs' => [
                'generated_at' => $docs['generated_at'] ?? '',
                'cutoff_date' => $docs['cutoff_date'] ?? '',
                'page_count' => $docs['page_count'] ?? 0,
                'endpoint_count' => $docs['endpoint_count'] ?? 0,
            ],
            'erp' => [
                'scanned_php_files' => $usage['scanned_php_files'] ?? 0,
                'api_calls_detected' => count($usage['api_calls'] ?? []),
                'public_catalog_api_hits' => count($usage['public_catalog_api_hits'] ?? []),
            ],
            'endpoint_status_counts' => $statuses,
            'field_counts_by_module' => $fieldByModule,
        ];
    }

    /**
     * @param list<array<string,mixed>> $endpointContracts
     * @param list<array<string,mixed>> $fields
     * @param array<string,mixed> $usage
     * @return list<array<string,mixed>>
     */
    private function risks(array $endpointContracts, array $fields, array $usage): array
    {
        $risks = [];
        foreach ($endpointContracts as $endpoint) {
            $path = (string) $endpoint['normalized_path'];
            $status = (string) $endpoint['status'];
            if ($status === 'used_not_registered') {
                $risks[] = $this->risk('critico', 'Endpoint usado pero no registrado', $path, 'Bloquea o deja sin control una llamada Mercado Libre.', 'Registrar o eliminar la llamada antes de producción.');
            }
            if ($status === 'registered_not_documented') {
                $risks[] = $this->risk('alto', 'Endpoint registrado sin evidencia local suficiente', $path, 'El ERP podría permitir una llamada no validada contra la documentación materializada.', 'Revisar contra docs ML y marcar confirmed/investigating/disabled.');
            }
            if ($path === '/payments/{id}') {
                $risks[] = $this->risk('alto', 'Pago expandido debe seguir desactivado', $path, 'Mercado Libre no debe consultarse con /payments/{id} por defecto; pagos vienen embebidos en órdenes.', 'Mantener payments.expand_details_enabled=false y auditar cualquier 404 heredado.');
            }
            if ($path === '/users/{seller_id}/items/search') {
                $risks[] = $this->risk('medio', 'Productos ML usan offset/limit', $path, 'En catálogos grandes puede saltar o repetir resultados si hay cambios mientras se pagina.', 'Evaluar search_type=scan/scroll_id en job seguro y nunca en catálogo público.');
            }
            if ($path === '/questions/search') {
                $risks[] = $this->risk('medio', 'Preguntas requieren confirmar parámetros', $path, 'La documentación local menciona api_version=4; el servicio actual usa seller_id/status/limit.', 'Agregar api_version=4 si la página oficial local lo confirma para Colombia/cuenta.');
            }
            if ($path === '/post-purchase/v1/claims/search') {
                $risks[] = $this->risk('medio', 'Reclamos requieren filtros de participante', $path, 'La documentación muestra players.user_id y players.role; el servicio actual consulta opened+limit.', 'Validar filtros correctos por cuenta para evitar resultados incompletos o inesperados.');
            }
            if ($path === '/billing/integration/group/ML/order/details') {
                $risks[] = $this->risk('alto', 'Billing financiero usa parsing flexible', $path, 'El parser recursivo puede clasificar mal líneas si la respuesta oficial tiene nombres distintos.', 'Contrastar con schema oficial y fijar mapping determinístico por tipo/campo.');
            }
            if ($path === '/user-products/{user_product_id}/stock') {
                $risks[] = $this->risk('medio', 'Stock multi-origen depende de permisos', $path, 'Si la cuenta no tiene permiso o user_product_id, no debe inventarse stock FULL/local.', 'Mostrar no_disponible y guardar cooldown por cuenta/endpoint si hay 403.');
            }
        }
        if (count($usage['public_catalog_api_hits'] ?? []) > 0) {
            $risks[] = $this->risk('critico', 'Catálogo público referencia MeliApiClient', 'public_catalog', 'La vista pública no debe consultar API ni cargar clases de integración ML.', 'Eliminar la dependencia pública y usar solo snapshots locales.');
        }
        foreach ($fields as $field) {
            if (($field['duplicate_risk'] ?? '') === 'alto') {
                $risks[] = $this->risk('medio', 'Campo con riesgo de duplicado', (string) $field['remote_field'], 'Puede duplicar registros si no se usa clave por cuenta + ID externo.', (string) $field['recommendation']);
            }
        }
        return $risks;
    }

    /**
     * @return array<string,string>
     */
    private function risk(string $severity, string $title, string $subject, string $impact, string $recommendation): array
    {
        return compact('severity', 'title', 'subject', 'impact', 'recommendation');
    }

    private function renderMainAudit(string $version, array $docs, array $endpoints, array $fields, array $coverage, array $risks, array $sourceSummary): string
    {
        $critical = count(array_filter($risks, static fn ($r): bool => ($r['severity'] ?? '') === 'critico'));
        $high = count(array_filter($risks, static fn ($r): bool => ($r['severity'] ?? '') === 'alto'));
        $medium = count(array_filter($risks, static fn ($r): bool => ($r['severity'] ?? '') === 'medio'));
        $publicHits = (int) ($coverage['erp']['public_catalog_api_hits'] ?? 0);
        $statusRows = $this->markdownKeyValue($coverage['endpoint_status_counts'] ?? []);
        $riskRows = $this->riskTable($risks);
        $modules = $this->moduleSection($fields);

        return "# Auditoría API Mercado Libre — ERP Meli / Gestión Pro {$version}\n\n"
            . "Generado: " . gmdate('c') . "\n\n"
            . "## Resumen ejecutivo\n\n"
            . "- Documentación Mercado Libre usada: captura local con fecha de corte `" . ($docs['cutoff_date'] ?? '') . "`.\n"
            . "- Páginas oficiales capturadas: `" . ($docs['page_count'] ?? 0) . "`.\n"
            . "- Endpoints detectados en documentación: `" . ($docs['endpoint_count'] ?? 0) . "`.\n"
            . "- Contratos endpoint materializados para ERP: `" . count($endpoints) . "`.\n"
            . "- Variables/campos auditados: `" . count($fields) . "`.\n"
            . "- Riesgos críticos/altos/medios: `{$critical}` / `{$high}` / `{$medium}`.\n"
            . "- Referencias API desde catálogo público: `{$publicHits}`.\n\n"
            . "El ERP tiene una arquitectura correcta de protección API: `MeliApiClient`, `MeliEndpointRegistry`, `ApiGuardService` y `WriteGuard`. La mejora clave es mantener la documentación materializada como contrato verificable para que futuras versiones no agreguen endpoints o variables sin evidencia documental.\n\n"
            . "## Materialización creada\n\n"
            . "- `resources/mercadolibre-api/source/`: copia sanitizada de la documentación fuente.\n"
            . "- `resources/mercadolibre-api/generated/endpoints.json`: contratos por endpoint.\n"
            . "- `resources/mercadolibre-api/generated/fields.json`: contrato por variable.\n"
            . "- `resources/mercadolibre-api/generated/erp-usage.json`: llamadas reales detectadas en código.\n"
            . "- `resources/mercadolibre-api/generated/coverage.json`: cobertura de documentación/ERP.\n"
            . "- `resources/mercadolibre-api/generated/risks.json`: riesgos priorizados.\n\n"
            . "## Estado de endpoints\n\n{$statusRows}\n\n"
            . "## Riesgos principales\n\n{$riskRows}\n\n"
            . "## Evaluación por módulo\n\n{$modules}\n\n"
            . "## Política recomendada\n\n"
            . "- Mantener `ML_WRITE_ENABLED=false` para bloquear mutaciones remotas.\n"
            . "- No usar `/payments/{id}` por defecto; pagos deben venir de `orders[].payments[]`.\n"
            . "- No llamar API desde catálogo público; solo snapshots locales.\n"
            . "- Resolver preguntas/reclamos con parámetros confirmados en documentación local.\n"
            . "- Pasar productos grandes a flujo paginado seguro por job, evaluando `scan/scroll_id` si queda confirmado.\n"
            . "- Validar el schema real de billing antes de confiar en clasificaciones financieras definitivas.\n\n"
            . "## Seguridad de fuentes\n\n"
            . "- Archivos fuente copiados: `" . ($sourceSummary['copied'] ?? 0) . "`.\n"
            . "- Secretos detectados y omitidos: `" . ($sourceSummary['secrets_detected'] ?? 0) . "`.\n"
            . "- Los reportes no incluyen tokens, refresh tokens, credenciales, compradores, ventas reales ni raw JSON productivo.\n";
    }

    private function renderEndpointMatrix(string $version, array $endpoints): string
    {
        $out = '# Matriz de endpoints Mercado Libre — ERP Meli '
            . $this->md($version) . "\n\n";
        $out .= "| Estado | Endpoint | Módulo ERP | Contexto | Tablas | Riesgo | Recomendación |\n";
        $out .= "|---|---|---|---|---|---|---|\n";
        foreach ($endpoints as $row) {
            $out .= '| ' . $this->md((string) ($row['status'] ?? '')) .
                ' | `' . $this->md((string) ($row['normalized_path'] ?? '')) . '`' .
                ' | ' . $this->md(implode(', ', $row['erp_modules'] ?? [])) .
                ' | ' . $this->md(implode(', ', $row['contexts'] ?? [])) .
                ' | ' . $this->md(implode(', ', $row['local_tables'] ?? [])) .
                ' | ' . $this->md((string) ($row['rate_limit_risk'] ?? '')) .
                ' | ' . $this->md((string) ($row['recommendation'] ?? '')) . " |\n";
        }
        $out .= "\n## Detalle JSON\n\nLa versión estructurada está en `resources/mercadolibre-api/generated/endpoints.json`.\n";
        return $out;
    }

    private function renderFieldMatrix(string $version, array $fields): string
    {
        $out = '# Matriz de variables Mercado Libre — ERP Meli '
            . $this->md($version) . "\n\n";
        $out .= "| Endpoint | Campo remoto | Tipo | Destino local | Servicio | Normalización | Afecta | Recomendación |\n";
        $out .= "|---|---|---|---|---|---|---|---|\n";
        foreach ($fields as $field) {
            $out .= '| `' . $this->md((string) ($field['endpoint'] ?? '')) . '`' .
                ' | `' . $this->md((string) ($field['remote_field'] ?? '')) . '`' .
                ' | ' . $this->md((string) ($field['expected_type'] ?? '')) .
                ' | `' . $this->md((string) ($field['local_target'] ?? '')) . '`' .
                ' | ' . $this->md((string) ($field['service'] ?? '')) .
                ' | ' . $this->md((string) ($field['normalizer'] ?? '')) .
                ' | ' . $this->md(implode(', ', $field['affects'] ?? [])) .
                ' | ' . $this->md((string) ($field['recommendation'] ?? '')) . " |\n";
        }
        $out .= "\nLa versión estructurada está en `resources/mercadolibre-api/generated/fields.json`.\n";
        return $out;
    }

    private function renderRisks(string $version, array $risks): string
    {
        return '# Riesgos y optimizaciones API Mercado Libre — ERP Meli '
            . $this->md($version) . "\n\n"
            . $this->riskTable($risks)
            . "\n## Lectura rápida\n\n"
            . "- `critico`: corregir antes de nuevas funciones API.\n"
            . "- `alto`: planificar versión correctiva corta.\n"
            . "- `medio`: mejorar cuando toque el módulo relacionado.\n"
            . "- `bajo`: deuda técnica aceptable con monitoreo.\n";
    }

    private function renderChecklist(
        string $version,
        array $risks,
        array $endpoints
    ): string
    {
        $out = '# Checklist de corrección API Mercado Libre — ERP Meli '
            . $this->md($version) . "\n\n";
        $out .= "## Antes de implementar nuevos endpoints\n\n";
        $out .= "- [ ] Confirmar endpoint en `resources/mercadolibre-api/generated/endpoints.json`.\n";
        $out .= "- [ ] Agregar patrón a `MeliEndpointRegistry` solo si está confirmado.\n";
        $out .= "- [ ] Definir campos destino en `resources/mercadolibre-api/generated/fields.json`.\n";
        $out .= "- [ ] Confirmar que la llamada pasa por `MeliApiClient`.\n";
        $out .= "- [ ] Confirmar `ApiGuardService`, `Retry-After`, 429/403 y circuit breaker.\n";
        $out .= "- [ ] Confirmar que no es escritura remota o que `WriteGuard` la bloquea.\n";
        $out .= "- [ ] Confirmar que no se llama desde vistas públicas.\n\n";
        $out .= "## Correcciones priorizadas\n\n";
        foreach ($risks as $risk) {
            $out .= "- [ ] **" . $this->md((string) $risk['severity']) . "** — " . $this->md((string) $risk['title']) . ': ' . $this->md((string) $risk['recommendation']) . "\n";
        }
        $out .= "\n## Endpoints a revisar en cada release\n\n";
        foreach ($endpoints as $endpoint) {
            if (in_array(($endpoint['status'] ?? ''), ['used_not_registered', 'registered_not_documented', 'disabled', 'dangerous', 'investigating'], true)) {
                $out .= "- [ ] `" . $this->md((string) $endpoint['normalized_path']) . "` — " . $this->md((string) $endpoint['recommendation']) . "\n";
            }
        }
        return $out;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root, array $skipDirs): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $relative = $this->relativePath($root, $file->getPathname());
            foreach ($skipDirs as $skip) {
                if (str_starts_with($relative, trim($skip, '/') . '/')) {
                    continue 2;
                }
            }
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
        sort($files);
        return $files;
    }

    private function normalizeEndpointPath(string $path): string
    {
        $path = trim($path);
        $path = preg_replace('~^https?://api\.mercadolibre\.com~i', '', $path) ?? $path;
        $path = preg_replace('~\?.*$~', '', $path) ?? $path;
        $path = preg_replace('~\$[A-Z0-9_]+~i', '{id}', $path) ?? $path;
        $path = preg_replace('~\{ITEM_ID\}~i', '{id}', $path) ?? $path;
        $path = preg_replace('~\{(?:SITE_ID|CATEGORY_ID|CANDIDATE_ID|OFFERS?_ID|OFFER_ID)\}~i', '{id}', $path) ?? $path;
        $path = preg_replace('~/items/(ITEM_ID|Item_id|item_id)\b~', '/items/{id}', $path) ?? $path;
        $path = preg_replace('~/categories/(CATEGORY_ID|category_id)\b~', '/categories/{id}', $path) ?? $path;
        $path = preg_replace('~\{USER_PRODUCT_ID\}~i', '{user_product_id}', $path) ?? $path;
        $path = preg_replace('~/orders/\d+~', '/orders/{id}', $path) ?? $path;
        $path = preg_replace('~/packs/\d+~', '/packs/{id}', $path) ?? $path;
        $path = preg_replace('~/shipments/\d+~', '/shipments/{id}', $path) ?? $path;
        $path = preg_replace('~/payments/\d+~', '/payments/{id}', $path) ?? $path;
        $path = preg_replace('~/items/[A-Z]{2,4}\d+~i', '/items/{id}', $path) ?? $path;
        $path = preg_replace('~/categories/[A-Z]{2,4}\d+~i', '/categories/{id}', $path) ?? $path;
        $path = preg_replace('~/users/\d+/items/search~', '/users/{seller_id}/items/search', $path) ?? $path;
        $path = preg_replace('~/users/\{id\}/items/search~', '/users/{seller_id}/items/search', $path) ?? $path;
        $path = preg_replace('~/post-purchase/v1/claims/\d+~', '/post-purchase/v1/claims/{id}', $path) ?? $path;
        $path = preg_replace('~/questions/\d+~', '/questions/{id}', $path) ?? $path;
        $path = preg_replace('~/user-products/[A-Z0-9_-]+/stock~i', '/user-products/{user_product_id}/stock', $path) ?? $path;
        return '/' . ltrim($path, '/');
    }

    private function patternToEndpoint(string $pattern): string
    {
        $path = preg_replace('/~[a-zA-Z]*$/', '', ltrim($pattern, '~')) ?? $pattern;
        $path = preg_replace('~^\^~', '', $path) ?? $path;
        $path = preg_replace('~\$$~', '', $path) ?? $path;
        $path = preg_replace('~(?:CANDIDATE|OFFER)-\[A-Z\]\{2,4\}\\\\d\+-\\\\d\+~', '{id}', $path) ?? $path;
        $path = str_replace('/user-products/[A-Z0-9_-]+/stock', '/user-products/{user_product_id}/stock', $path);
        $path = str_replace(['[A-Z]{2,4}\d+', '[A-Z]{3}', '[A-Z0-9_-]+', '\d+'], ['{id}', '{id}', '{id}', '{id}'], $path);
        $path = str_replace(['(actions-history|status-history|affects-reputation)'], ['{subresource}'], $path);
        $path = str_replace('\\/', '/', $path);
        return $this->normalizeEndpointPath($path);
    }

    private function expressionToEndpoint(string $expression): string
    {
        $expr = trim($expression);
        if (str_contains($expr, '/billing/integration/group/ML/order/details')) {
            return '/billing/integration/group/ML/order/details';
        }
        if (str_contains($expr, '/orders/search')) {
            return '/orders/search';
        }
        if (preg_match("~['\"]\/orders\/['\"]\s*\.~", $expr)) {
            return '/orders/{id}';
        }
        if (preg_match("~['\"]\/packs\/['\"]\s*\.~", $expr)) {
            return '/packs/{id}';
        }
        if (preg_match("~['\"]\/shipments\/['\"]\s*\.~", $expr)) {
            return '/shipments/{id}';
        }
        if (preg_match("~['\"]\/payments\/['\"]\s*\.~", $expr)) {
            return '/payments/{id}';
        }
        if (preg_match("~['\"]\/items\/['\"]\s*\.~", $expr)) {
            return str_contains($expr, '/description') ? '/items/{id}/description' : '/items/{id}';
        }
        if (preg_match("~['\"]\/categories\/['\"]\s*\.~", $expr)) {
            return '/categories/{id}';
        }
        if (preg_match("~['\"]\/questions\/['\"]\s*\.~", $expr)) {
            return '/questions/{id}';
        }
        if (preg_match("~['\"]\/post-purchase/v1/claims\/['\"]\s*\.~", $expr)) {
            if (str_contains($expr, '/detail')) {
                return '/post-purchase/v1/claims/{id}/detail';
            }
            return '/post-purchase/v1/claims/{id}';
        }
        if (preg_match("~['\"]\/user-products\/['\"]\s*\.~", $expr)) {
            return '/user-products/{user_product_id}/stock';
        }
        if (preg_match("~['\"]\/users\/['\"]\s*\.~", $expr) && str_contains($expr, '/items/search')) {
            return '/users/{seller_id}/items/search';
        }
        if (preg_match("/['\"](\/[^'\"]+)['\"]/", $expr, $m)) {
            $path = $m[1];
        } else {
            $path = $expr;
        }
        $path = preg_replace('~/orders/\s*\'?\s*\.~', '/orders/{id}', $path) ?? $path;
        $path = preg_replace('~/packs/\s*\'?\s*\.~', '/packs/{id}', $path) ?? $path;
        $path = preg_replace('~/shipments/\s*\'?\s*\.~', '/shipments/{id}', $path) ?? $path;
        $path = preg_replace('~/payments/\s*\'?\s*\.~', '/payments/{id}', $path) ?? $path;
        $path = preg_replace('~/items/\s*\'?\s*\.~', '/items/{id}', $path) ?? $path;
        $path = preg_replace('~/categories/\s*\'?\s*\.~', '/categories/{id}', $path) ?? $path;
        $path = preg_replace('~/questions/\s*\'?\s*\.~', '/questions/{id}', $path) ?? $path;
        $path = preg_replace('~/post-purchase/v1/claims/\s*\'?\s*\.~', '/post-purchase/v1/claims/{id}', $path) ?? $path;
        $path = preg_replace('~/user-products/\s*\'?\s*\.~', '/user-products/{user_product_id}/stock', $path) ?? $path;
        $path = preg_replace('~/users/\s*\'?\s*\.~', '/users/{seller_id}/items/search', $path) ?? $path;
        return $this->normalizeEndpointPath($path);
    }

    private function endpointStatus(string $path, array $docRows, array $regRows, array $useRows): string
    {
        if ($path === '/payments/{id}') {
            return 'disabled';
        }
        if ($docRows && $regRows && $useRows) {
            return 'confirmed_used';
        }
        if ($docRows && $regRows) {
            return 'confirmed_registered';
        }
        if ($docRows && $useRows) {
            return 'used_not_registered';
        }
        if ($regRows && $useRows) {
            return 'registered_not_documented';
        }
        if ($docRows) {
            return 'documented_not_used';
        }
        if ($regRows) {
            return 'registered_not_documented';
        }
        return 'unknown';
    }

    private function sourcePages(array $docRows): array
    {
        $pages = [];
        foreach ($docRows as $row) {
            foreach (($row['source_pages'] ?? []) as $page) {
                if (is_array($page) && !isset($pages[$page['url'] ?? ''])) {
                    $pages[(string) ($page['url'] ?? '')] = $page;
                }
            }
        }
        return array_values(array_filter($pages));
    }

    private function knownParameters(string $path): array
    {
        return match ($path) {
            '/orders/search' => ['seller', 'order.date_created.from', 'order.date_created.to', 'order.status', 'q', 'sort', 'offset', 'limit'],
            '/users/{seller_id}/items/search' => ['offset', 'limit', 'status', 'search_type', 'scroll_id', 'sku', 'q'],
            '/questions/search' => ['seller_id', 'status', 'limit', 'api_version', 'sort_fields'],
            '/post-purchase/v1/claims/search' => ['status', 'limit', 'players.user_id', 'players.role'],
            '/billing/integration/group/ML/order/details' => ['order_ids'],
            default => [],
        };
    }

    private function fieldsForEndpoint(string $path): array
    {
        return array_values(array_map(static fn ($f): string => (string) $f['remote_field'], array_filter($this->fieldContracts(), static fn ($f): bool => ($f['endpoint'] ?? '') === $path)));
    }

    private function tablesForEndpoint(string $path): array
    {
        $tables = [];
        foreach ($this->fieldsForEndpoint($path) as $ignored) {
            foreach ($this->fieldContracts() as $field) {
                if (($field['endpoint'] ?? '') !== $path) {
                    continue;
                }
                $target = (string) ($field['local_target'] ?? '');
                if (preg_match_all('/\b([a-z][a-z0-9_]+)\.[a-z0-9_*]+/i', $target, $matches)) {
                    foreach ($matches[1] as $table) {
                        $tables[] = $table;
                    }
                }
            }
        }
        return array_values(array_unique($tables));
    }

    private function allowedContexts(string $path, string $status): array
    {
        if ($status === 'disabled') {
            return ['desactivado_por_defecto'];
        }
        if (in_array($path, ['/items/{id}/description', '/user-products/{user_product_id}/stock', '/billing/integration/group/ML/order/details'], true)) {
            return ['job', 'admin', 'cron', 'nunca_publico'];
        }
        if (in_array($path, ['/orders/search', '/orders/{id}', '/shipments/{id}', '/packs/{id}', '/questions/search', '/post-purchase/v1/claims/search'], true)) {
            return ['cron', 'job', 'admin', 'sync', 'nunca_publico'];
        }
        return ['admin', 'job', 'nunca_publico'];
    }

    private function rateRisk(string $path): string
    {
        return match ($path) {
            '/orders/search', '/users/{seller_id}/items/search', '/items/{id}', '/billing/integration/group/ML/order/details' => 'alto_si_masivo',
            '/items/{id}/description', '/user-products/{user_product_id}/stock', '/questions/search', '/post-purchase/v1/claims/search' => 'medio',
            '/payments/{id}' => 'alto_desactivado',
            default => 'bajo_medio',
        };
    }

    private function endpointRecommendation(string $path, string $status): string
    {
        if ($path === '/payments/{id}') {
            return 'Mantener desactivado por defecto; usar payments embebidos en órdenes.';
        }
        if ($path === '/questions/search') {
            return 'Validar api_version=4 y parámetros exactos contra documentación local.';
        }
        if ($path === '/post-purchase/v1/claims/search') {
            return 'Agregar/validar players.user_id y players.role si aplica al vendedor conectado.';
        }
        if ($path === '/users/{seller_id}/items/search') {
            return 'Para catálogos grandes evaluar scan/scroll_id en job seguro, no en vista pública.';
        }
        if ($path === '/billing/integration/group/ML/order/details') {
            return 'Fijar mapeo de líneas según schema documentado y mantener lotes <=60.';
        }
        if ($status === 'used_not_registered') {
            return 'Bloquear hasta registrarlo o retirar la llamada.';
        }
        if ($status === 'registered_not_documented') {
            return 'Revisar evidencia local y marcar como confirmed o investigating.';
        }
        return 'Uso aceptable si pasa por MeliApiClient, ApiGuardService y no se ejecuta desde público.';
    }

    private function moduleForFile(string $relative): string
    {
        $map = [
            'Order' => 'ordenes',
            'Payment' => 'pagos',
            'Shipment' => 'envios',
            'Claim' => 'reclamos',
            'Question' => 'preguntas',
            'MeliItem' => 'productos_ml',
            'Catalog' => 'catalogos',
            'Billing' => 'facturacion',
            'Financial' => 'conciliacion',
            'Webhook' => 'webhooks',
            'Sync' => 'sincronizacion',
        ];
        foreach ($map as $needle => $module) {
            if (str_contains($relative, $needle)) {
                return $module;
            }
        }
        return str_starts_with($relative, 'jobs/') ? 'jobs' : 'general';
    }

    private function contextForFile(string $relative): string
    {
        if (str_starts_with($relative, 'jobs/')) {
            return 'cron_job';
        }
        if (str_contains($relative, 'Controller')) {
            return 'controller';
        }
        if (str_contains($relative, 'Views/public_catalog')) {
            return 'vista_publica';
        }
        if (str_contains($relative, 'Views/')) {
            return 'vista_interna';
        }
        return 'service';
    }

    private function fakeExample(string $field, string $type): string
    {
        if (str_contains($field, 'date')) {
            return '2026-07-25T10:15:00.000-04:00';
        }
        if (str_contains($type, 'int')) {
            return '123';
        }
        if (str_contains($type, 'decimal')) {
            return '19990.00';
        }
        if (str_contains($type, 'array')) {
            return '[]';
        }
        if (str_contains($field, 'id')) {
            return 'MCO123456789';
        }
        return 'valor_ejemplo';
    }

    private function normalizerForField(string $field): string
    {
        if (str_contains($field, 'date') || str_contains($field, '_at')) {
            return 'MeliDateTimeNormalizer / DateTimeImmutable UTC';
        }
        if (str_contains($field, 'price') || str_contains($field, 'amount') || str_contains($field, 'cost')) {
            return 'decimal(12,2) / redondeo financiero';
        }
        if (str_contains($field, 'pictures') || str_contains($field, 'attributes') || str_contains($field, 'variations')) {
            return 'normalización de arrays + hash para diferencias';
        }
        return 'casting defensivo según tipo';
    }

    private function dataKindForField(string $field): string
    {
        if (str_contains($field, 'pictures') || str_contains($field, 'title') || str_contains($field, 'price') || str_contains($field, 'stock') || str_contains($field, 'available_quantity')) {
            return 'snapshot_actualizable';
        }
        if (str_contains($field, 'payments') || str_contains($field, 'billing')) {
            return 'financiero_historico';
        }
        if (str_contains($field, 'date')) {
            return 'fecha_operativa';
        }
        return 'operativo';
    }

    private function duplicateRiskForField(string $field): string
    {
        return in_array($field, ['id', 'results[]', 'pictures[]', 'variations[]', 'attributes[]', 'billing lines'], true) ? 'alto' : 'normal';
    }

    private function dedupeUsage(array $calls): array
    {
        $seen = [];
        $out = [];
        foreach ($calls as $call) {
            $key = implode('|', [(string) $call['normalized_path'], (string) $call['file'], (string) $call['line']]);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $call;
        }
        return $out;
    }

    private function relativePath(string $root, string $file): string
    {
        $file = str_replace('\\', '/', $file);
        return ltrim(str_replace(rtrim($root, '/') . '/', '', $file), '/');
    }

    private function ensureDirectory(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear directorio: ' . $dir);
        }
    }

    private function writeJson(string $path, mixed $value): void
    {
        file_put_contents($path, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function markdownKeyValue(array $values): string
    {
        $out = "| Estado | Cantidad |\n|---|---:|\n";
        foreach ($values as $key => $value) {
            $out .= '| ' . $this->md((string) $key) . ' | ' . (int) $value . " |\n";
        }
        return $out;
    }

    private function riskTable(array $risks): string
    {
        $out = "| Severidad | Tema | Sujeto | Impacto | Recomendación |\n|---|---|---|---|---|\n";
        foreach ($risks as $risk) {
            $out .= '| ' . $this->md((string) ($risk['severity'] ?? '')) .
                ' | ' . $this->md((string) ($risk['title'] ?? '')) .
                ' | `' . $this->md((string) ($risk['subject'] ?? '')) . '`' .
                ' | ' . $this->md((string) ($risk['impact'] ?? '')) .
                ' | ' . $this->md((string) ($risk['recommendation'] ?? '')) . " |\n";
        }
        return $out;
    }

    private function moduleSection(array $fields): string
    {
        $groups = [];
        foreach ($fields as $field) {
            foreach (($field['affects'] ?? []) as $module) {
                $groups[(string) $module][] = $field;
            }
        }
        ksort($groups);
        $out = '';
        foreach ($groups as $module => $rows) {
            $out .= "### " . ucfirst(str_replace('_', ' ', $module)) . "\n\n";
            foreach (array_slice($rows, 0, 8) as $field) {
                $out .= "- `" . $this->md((string) $field['remote_field']) . "` desde `" . $this->md((string) $field['endpoint']) . "` → `" . $this->md((string) $field['local_target']) . "`.\n";
            }
            if (count($rows) > 8) {
                $out .= "- ... " . (count($rows) - 8) . " campos adicionales en la matriz de variables.\n";
            }
            $out .= "\n";
        }
        return $out;
    }

    private function md(string $value): string
    {
        return str_replace(["\r", "\n", '|'], [' ', ' ', '\|'], $value);
    }
}
