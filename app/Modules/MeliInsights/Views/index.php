<?php

use App\Core\Auth;
use App\Core\View;

$base = rtrim(\App\Core\Env::get('APP_URL', ''), '/');
$labels = [
    'performance' => ['title' => 'Rendimiento y calidad', 'description' => 'Oportunidades para mejorar la exposición y calidad de sus publicaciones.'],
    'pricing' => ['title' => 'Precios y competencia', 'description' => 'Precio efectivo observado y posición competitiva, sin modificar publicaciones.'],
    'moderations' => ['title' => 'Moderaciones', 'description' => 'Restricciones activas, motivos y campos que conviene revisar en Mercado Libre.'],
    'capabilities' => ['title' => 'Capacidades por cuenta', 'description' => 'Disponibilidad real de cada operación de Mercado Libre para sus cuentas.'],
];
$current = $labels[$page] ?? $labels['performance'];
$statusLabels = [
    'pending' => 'Pendiente de comprobar',
    'supported' => 'Disponible',
    'permission_required' => 'Permiso requerido',
    'unsupported' => 'No disponible',
    'temporarily_unavailable' => 'Limitada temporalmente',
    'available' => 'Disponible para la publicación',
    'item_not_eligible' => 'Publicación no elegible',
    'expected_absence' => 'Información opcional ausente',
    'confirmed' => 'Confirmado',
    'active' => 'Activa',
    'resolved' => 'Resuelta',
    'not_eligible' => 'No elegible',
    'unknown' => 'No identificado',
    'running' => 'Procesando',
    'retry' => 'Reintentará',
    'paused' => 'Pausado',
    'completed' => 'Completado',
    'failed' => 'Con error',
    'cancelled' => 'Cancelado',
];
$stageLabels = [
    'queued' => 'En espera',
    'capabilities' => 'Comprobando capacidades',
    'reputation' => 'Consultando reputación',
    'prices' => 'Actualizando precios',
    'performance' => 'Evaluando calidad',
    'moderations' => 'Revisando moderaciones',
    'competition' => 'Comparando catálogo',
    'next_account' => 'Preparando siguiente cuenta',
    'completed' => 'Finalizado',
    'error' => 'Requiere revisión',
];
$jsonList = static function (mixed $value): array {
    $decoded = json_decode((string) ($value ?? '[]'), true);
    if (!is_array($decoded)) {
        return [];
    }
    if (array_is_list($decoded)) {
        return $decoded;
    }
    return array_values($decoded);
};
$itemTitle = static fn (array $row): string => (string) (($row['item']['title'] ?? null) ?: ($row['external_item_id'] ?? 'Publicación'));
$money = static fn (mixed $amount, mixed $currency): string =>
    $amount === null || $amount === '' ? '—' : trim((string) $currency . ' ' . number_format((float) $amount, 0, ',', '.'));
?>
<section class="module-page insights-page" data-module="meli-insights" data-insights-page="<?= View::e($page) ?>">
    <header class="page-header module-page__header">
        <div>
            <p class="eyebrow">MERCADO LIBRE · SOLO LECTURA</p>
            <h1><?= View::e($current['title']) ?></h1>
            <p><?= View::e($current['description']) ?></p>
        </div>
        <?php if (in_array(Auth::role(), ['admin', 'operator'], true)): ?>
            <form method="post" action="<?= View::e($base) ?>/meli-insights/refresh">
                <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
                <input type="hidden" name="page" value="<?= View::e($page) ?>">
                <input type="hidden" name="meli_account_id" value="<?= (int) $filters['account_id'] ?>">
                <button class="btn btn-primary" type="submit">Actualizar datos</button>
            </form>
        <?php endif; ?>
    </header>

    <nav class="module-tabs" aria-label="Secciones de Meli Insights">
        <?php foreach ($labels as $key => $definition): ?>
            <?php if ($key === 'capabilities' && Auth::role() !== 'admin') { continue; } ?>
            <a href="<?= View::e($base . '/meli-insights/' . $key) ?>" <?= $page === $key ? 'aria-current="page"' : '' ?>>
                <?= View::e($definition['title']) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="module-status" role="status">
        <strong><?= ($state['status'] ?? '') === 'enabled' ? 'Módulo habilitado' : 'Módulo pendiente de habilitar' ?></strong>
        <span>Versión <?= View::e($module?->version() ?? '1.1.1') ?></span>
        <span><?= $lastSnapshotAt ? 'Último dato: ' . View::e($lastSnapshotAt) : 'Todavía no hay snapshots confirmados' ?></span>
    </div>

    <form class="module-filters" method="get" action="<?= View::e($base . '/meli-insights/' . $page) ?>">
        <label>Cuenta
            <select name="account_id">
                <option value="0">Todas las cuentas</option>
                <?php foreach ($accounts as $account): ?>
                    <option value="<?= (int) $account['id'] ?>" <?= (int) $filters['account_id'] === (int) $account['id'] ? 'selected' : '' ?>>
                        <?= View::e((string) ($account['account_name'] ?: $account['nickname'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Estado
            <select name="status">
                <option value="">Todos los estados</option>
                <?php
                $filterStates = $page === 'capabilities'
                    ? ['pending', 'supported', 'permission_required', 'unsupported', 'temporarily_unavailable']
                    : ['confirmed', 'active', 'resolved', 'not_eligible', 'unknown'];
                foreach ($filterStates as $filterState):
                ?>
                    <option value="<?= View::e($filterState) ?>" <?= $filters['status'] === $filterState ? 'selected' : '' ?>>
                        <?= View::e($statusLabels[$filterState] ?? $filterState) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Desde <input type="date" name="from" value="<?= View::e((string) ($filters['from'] ?? '')) ?>"></label>
        <label>Hasta <input type="date" name="to" value="<?= View::e((string) ($filters['to'] ?? '')) ?>"></label>
        <button class="btn btn-secondary" type="submit">Aplicar filtros</button>
    </form>

    <div class="module-metrics" aria-label="Resumen de <?= View::e($current['title']) ?>">
        <?php foreach ($metrics as $metric): ?>
            <article class="metric-card metric-card--<?= View::e((string) $metric['tone']) ?>">
                <span><?= View::e((string) $metric['label']) ?></span>
                <strong><?= View::e((string) $metric['value']) ?></strong>
            </article>
        <?php endforeach; ?>
    </div>

    <?php if ($page === 'performance' && $reputation !== []): ?>
        <section class="card insights-reputation">
            <div class="card-header"><h2>Reputación de las cuentas</h2></div>
            <div class="insights-summary-list">
                <?php foreach ($reputation as $snapshot): ?>
                    <article>
                        <strong>Cuenta #<?= (int) $snapshot['meli_account_id'] ?></strong>
                        <span>Nivel <?= View::e((string) ($snapshot['level_id'] ?: 'no identificado')) ?></span>
                        <span><?= View::e((string) ($snapshot['power_seller_status'] ?: 'Sin nivel Power Seller')) ?></span>
                        <small>Snapshot <?= View::e((string) $snapshot['observed_on']) ?></small>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <section class="card">
        <div class="card-header">
            <h2><?= View::e($current['title']) ?></h2>
            <span><?= count($rows) ?> resultados visibles</span>
        </div>
        <?php if ($rows === []): ?>
            <div class="empty-state">
                <strong>Aún no hay información confirmada</strong>
                <p>
                    <?php if ($jobs !== []): ?>
                        Existe una actualización programada. Los datos aparecerán cuando el cron complete sus etapas.
                    <?php else: ?>
                        Programe una actualización para comprobar qué información admite cada cuenta.
                    <?php endif; ?>
                </p>
            </div>
        <?php elseif ($page === 'capabilities'): ?>
            <?php if ($itemCapabilities !== []): ?>
                <div class="alert info">
                    <strong>Elegibilidad de publicaciones</strong>
                    <p>Estos resultados pertenecen a publicaciones individuales y no deshabilitan la capacidad completa de la cuenta.</p>
                    <ul>
                    <?php foreach ($itemCapabilities as $itemCapability): ?>
                        <li><?= View::e(ucfirst(str_replace('_', ' ', (string)$itemCapability['capability']))) ?>:
                            <?= View::e($statusLabels[$itemCapability['status']] ?? (string)$itemCapability['status']) ?>
                            (<?= (int)$itemCapability['total'] ?>)
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <div class="insights-capability-list">
                <?php foreach ($rows as $row): ?>
                    <article>
                        <div>
                            <strong><?= View::e(ucfirst(str_replace('_', ' ', (string) $row['capability']))) ?></strong>
                            <span><?= View::e((string) $row['account_name']) ?></span>
                        </div>
                        <span class="status-badge status-badge--<?= View::e((string) $row['status']) ?>">
                            <?= View::e($statusLabels[$row['status']] ?? (string) $row['status']) ?>
                        </span>
                        <p><?= View::e((string) ($row['safe_message'] ?: 'Operación comprobada.')) ?></p>
                        <small>Comprobado: <?= View::e((string) $row['observed_at']) ?></small>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive insights-table">
                <table class="table">
                    <caption><?= View::e($current['title']) ?>, resultados filtrados</caption>
                    <thead>
                    <?php if ($page === 'pricing'): ?>
                        <tr><th>Publicación</th><th>Cuenta</th><th>Precio efectivo</th><th>Precio estándar</th><th>Competencia</th><th>Actualizado</th></tr>
                    <?php elseif ($page === 'moderations'): ?>
                        <tr><th>Publicación</th><th>Cuenta</th><th>Estado</th><th>Motivo</th><th>Campos</th><th>Actualizado</th></tr>
                    <?php else: ?>
                        <tr><th>Publicación</th><th>Cuenta</th><th>Nivel</th><th>Puntaje</th><th>Pendientes</th><th>Recomendaciones</th><th>Actualizado</th></tr>
                    <?php endif; ?>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <td data-label="Publicación"><strong><?= View::e($itemTitle($row)) ?></strong><small><?= View::e((string) $row['external_item_id']) ?></small></td>
                            <td data-label="Cuenta"><?= View::e((string) $row['account_name']) ?></td>
                            <?php if ($page === 'pricing'): ?>
                                <td data-label="Precio efectivo"><?= View::e($money($row['effective_amount'], $row['currency_id'])) ?></td>
                                <td data-label="Precio estándar"><?= View::e($money($row['standard_amount'], $row['currency_id'])) ?></td>
                                <td data-label="Competencia">
                                    <span class="status-badge"><?= View::e($statusLabels[$row['competition_status']] ?? (string) ($row['competition_status'] ?: 'Sin comprobar')) ?></span>
                                    <?php if ($row['suggested_price'] !== null): ?><small>Sugerido: <?= View::e($money($row['suggested_price'], $row['currency_id'])) ?></small><?php endif; ?>
                                </td>
                            <?php elseif ($page === 'moderations'): ?>
                                <td data-label="Estado"><span class="status-badge"><?= View::e($statusLabels[$row['status']] ?? (string) $row['status']) ?></span></td>
                                <td data-label="Motivo"><?= View::e((string) ($row['reason'] ?: 'Mercado Libre no informó un motivo.')) ?></td>
                                <td data-label="Campos"><?= View::e(implode(', ', array_map('strval', $jsonList($row['affected_fields_json']))) ?: '—') ?></td>
                            <?php else: ?>
                                <td data-label="Nivel"><?= View::e((string) ($row['level'] ?: 'No identificado')) ?></td>
                                <td data-label="Puntaje"><?= $row['score'] !== null ? View::e(number_format((float) $row['score'], 2, ',', '.')) : '—' ?></td>
                                <td data-label="Pendientes"><?= count($jsonList($row['pending_json'])) ?></td>
                                <td data-label="Recomendaciones"><?= count($jsonList($row['recommendations_json'])) ?></td>
                            <?php endif; ?>
                            <td data-label="Actualizado"><?= View::e((string) $row['observed_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <details class="card module-jobs" <?= $rows === [] ? 'open' : '' ?>>
        <summary>Trabajos y progreso</summary>
        <?php if ($jobs === []): ?>
            <div class="empty-state"><p>No existen trabajos programados.</p></div>
        <?php else: ?>
            <div class="module-job-list">
                <?php foreach ($jobs as $job): ?>
                    <?php
                    $total = max(1, (int) $job['progress_total']);
                    $currentProgress = min($total, max(0, (int) $job['progress_current']));
                    $percent = (int) floor(($currentProgress / $total) * 100);
                    ?>
                    <article>
                        <div class="module-job-list__heading">
                            <div>
                                <strong>Actualización #<?= (int) $job['id'] ?></strong>
                                <span><?= View::e($statusLabels[$job['status']] ?? (string) $job['status']) ?> · <?= View::e($stageLabels[$job['stage']] ?? (string) ($job['stage'] ?: 'En espera')) ?></span>
                            </div>
                            <strong><?= $percent ?>%</strong>
                        </div>
                        <progress value="<?= $currentProgress ?>" max="<?= $total ?>"><?= $percent ?>%</progress>
                        <?php if (!empty($job['safe_error_message'])): ?><p class="module-job-error"><?= View::e((string) $job['safe_error_message']) ?></p><?php endif; ?>
                        <small>Próxima ejecución: <?= View::e((string) ($job['next_run_at'] ?: 'cuando el cron esté disponible')) ?></small>
                        <?php if (in_array(Auth::role(), ['admin', 'operator'], true)): ?>
                            <form method="post" action="<?= View::e($base) ?>/meli-insights/jobs/action" class="module-job-actions">
                                <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
                                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                <input type="hidden" name="meli_account_id" value="<?= (int) $job['meli_account_id'] ?>">
                                <input type="hidden" name="page" value="<?= View::e($page) ?>">
                                <?php if (in_array($job['status'], ['pending', 'retry'], true)): ?><button class="btn btn-tertiary" name="action" value="pause" type="submit">Pausar</button><?php endif; ?>
                                <?php if (in_array($job['status'], ['paused', 'failed'], true)): ?><button class="btn btn-secondary" name="action" value="resume" type="submit">Continuar</button><?php endif; ?>
                                <?php if ($job['status'] === 'failed'): ?><button class="btn btn-secondary" name="action" value="retry" type="submit">Reintentar</button><?php endif; ?>
                                <?php if (in_array($job['status'], ['pending', 'retry', 'paused'], true)): ?><button class="btn btn-tertiary" name="action" value="cancel" type="submit">Cancelar</button><?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </details>
</section>
