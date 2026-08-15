<?php

use App\Core\Auth;
use App\Core\View;

$base = rtrim(\App\Core\Env::get('APP_URL', ''), '/');
$pages = [
    'promotions' => [
        'title' => 'Promociones',
        'description' => 'Campañas y oportunidades ofrecidas por Mercado Libre. El ERP no incorpora publicaciones automáticamente.',
    ],
    'performance' => [
        'title' => 'Rendimiento comercial',
        'description' => 'Visitas remotas comparadas con órdenes ya incorporadas al ERP.',
    ],
    'trends' => [
        'title' => 'Tendencias y oportunidades',
        'description' => 'Señales generales del mercado para explorar demanda; no representan ventas propias.',
    ],
];
$current = $pages[$page] ?? $pages['promotions'];
$statusLabels = [
    'pending' => 'Pendiente',
    'running' => 'Procesando',
    'retry' => 'Se reintentará',
    'paused' => 'Pausado',
    'completed' => 'Completado',
    'failed' => 'Con error',
    'cancelled' => 'Cancelado',
    'supported' => 'Disponible',
    'permission_required' => 'Permiso requerido',
    'expected_absence' => 'Información opcional ausente',
    'temporarily_limited' => 'Disponible más adelante',
    'temporarily_unavailable' => 'No se pudo comprobar',
    'started' => 'Activa',
    'active' => 'Activa',
    'candidate' => 'Candidata',
    'pending_approval' => 'Pendiente',
    'finished' => 'Finalizada',
];
$stageLabels = [
    'queued' => 'En espera',
    'promotions' => 'Consultando promociones',
    'visits' => 'Consultando visitas agregadas',
    'trends' => 'Actualizando tendencias',
    'highlights' => 'Buscando destacados',
    'conversion' => 'Calculando conversión local',
    'next_account' => 'Preparando la siguiente cuenta',
    'completed' => 'Finalizado',
];
$moduleEnabled = ($state['status'] ?? '') === 'enabled';
?>
<section class="module-page growth-page" data-module="meli-growth" data-growth-page="<?= View::e($page) ?>">
    <header class="page-header module-page__header">
        <div>
            <p class="eyebrow">CRECIMIENTO · MERCADO LIBRE · SOLO LECTURA</p>
            <h1><?= View::e($current['title']) ?></h1>
            <p><?= View::e($current['description']) ?></p>
        </div>
        <?php if ($moduleEnabled && in_array(Auth::role(), ['admin', 'operator'], true)): ?>
            <span class="muted">Actualización automática retirada; no se crearán trabajos nuevos.</span>
        <?php endif; ?>
    </header>

    <nav class="module-tabs" aria-label="Secciones de Crecimiento">
        <?php foreach ($pages as $key => $definition): ?>
            <a href="<?= View::e($base . '/meli-growth/' . $key) ?>" <?= $page === $key ? 'aria-current="page"' : '' ?>>
                <?= View::e($definition['title']) ?>
            </a>
        <?php endforeach; ?>
    </nav>

    <?php if (!$moduleEnabled): ?>
        <section class="card growth-conclusion growth-conclusion--neutral">
            <div>
                <span class="status-badge">Módulo deshabilitado</span>
                <h2>Growth está instalado, pero todavía no consulta Mercado Libre</h2>
                <p>Un administrador puede habilitarlo primero para una cuenta canaria desde Módulos Mercado Libre.</p>
            </div>
            <a class="btn btn-secondary" href="<?= View::e($base) ?>/settings/modules">Ver módulos</a>
        </section>
    <?php else: ?>
        <section class="card growth-conclusion">
            <div>
                <span class="status-badge status-badge--supported">Módulo activo</span>
                <h2><?= $lastSnapshotAt ? 'Información disponible para analizar' : 'Esperando la primera actualización' ?></h2>
                <p>
                    <?= $lastSnapshotAt
                        ? 'Último dato confirmado: ' . View::e($lastSnapshotAt) . '.'
                        : 'Programe una actualización. Cron procesará promociones, visitas, tendencias y destacados por etapas.' ?>
                </p>
            </div>
        </section>
    <?php endif; ?>

    <form class="module-filters growth-filters" method="get" action="<?= View::e($base . '/meli-growth/' . $page) ?>">
        <label for="growth-account">Cuenta</label>
        <select id="growth-account" name="account_id">
            <option value="0">Todas las cuentas</option>
            <?php foreach ($accounts as $account): ?>
                <option value="<?= (int) $account['id'] ?>" <?= (int) $filters['account_id'] === (int) $account['id'] ? 'selected' : '' ?>>
                    <?= View::e((string) ($account['account_name'] ?: $account['nickname'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($page === 'promotions'): ?>
            <label for="growth-status">Estado</label>
            <select id="growth-status" name="status">
                <option value="">Todos los estados</option>
                <?php foreach (['started', 'candidate', 'pending', 'finished'] as $filterStatus): ?>
                    <option value="<?= View::e($filterStatus) ?>" <?= $filters['status'] === $filterStatus ? 'selected' : '' ?>>
                        <?= View::e($statusLabels[$filterStatus] ?? ucfirst($filterStatus)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button class="btn btn-secondary" type="submit">Aplicar filtros</button>
    </form>

    <div class="module-metrics growth-metrics" aria-label="Resumen de <?= View::e($current['title']) ?>">
        <?php foreach ($metrics as $metric): ?>
            <article class="metric-card metric-card--<?= View::e($metric['tone']) ?>">
                <span><?= View::e($metric['label']) ?></span>
                <strong><?= View::e($metric['value']) ?></strong>
                <small><?= View::e($metric['help']) ?></small>
            </article>
        <?php endforeach; ?>
    </div>

    <section class="card growth-results">
        <div class="card-header">
            <div>
                <h2><?= View::e($current['title']) ?></h2>
                <p><?= count($rows) ?> resultados visibles</p>
            </div>
        </div>

        <?php if ($rows === []): ?>
            <div class="empty-state">
                <strong>No hay información comprobada todavía</strong>
                <p>
                    <?php if (!$moduleEnabled): ?>
                        Habilitar el módulo no modifica Mercado Libre; únicamente permite crear snapshots locales.
                    <?php elseif ($jobs !== []): ?>
                        Existe un trabajo programado. Revise su progreso al final de la pantalla.
                    <?php else: ?>
                        Programe una actualización para comprobar qué datos admite la cuenta seleccionada.
                    <?php endif; ?>
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive growth-table">
                <table class="table">
                    <caption><?= View::e($current['title']) ?>, información filtrada</caption>
                    <thead>
                    <?php if ($page === 'promotions'): ?>
                        <tr><th>Promoción</th><th>Cuenta</th><th>Tipo</th><th>Estado</th><th>Vigencia</th><th>Candidatos</th><th>Comprobado</th></tr>
                    <?php elseif ($page === 'performance'): ?>
                        <tr><th>Fecha</th><th>Cuenta</th><th>Visitas</th><th>Órdenes</th><th>Conversión estimada</th><th>Fuente</th></tr>
                    <?php else: ?>
                        <tr><th>Señal</th><th>Sitio</th><th>Categoría</th><th>Posición</th><th>Tipo</th><th>Comprobado</th></tr>
                    <?php endif; ?>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr>
                            <?php if ($page === 'promotions'): ?>
                                <td data-label="Promoción"><strong><?= View::e((string) ($row['name'] ?: 'Promoción sin nombre')) ?></strong><small><?= View::e((string) $row['external_promotion_id']) ?></small></td>
                                <td data-label="Cuenta"><?= View::e((string) $row['account_name']) ?></td>
                                <td data-label="Tipo"><?= View::e(str_replace('_', ' ', (string) ($row['promotion_type'] ?: 'No informado'))) ?></td>
                                <td data-label="Estado"><span class="status-badge"><?= View::e($statusLabels[strtolower((string) $row['status'])] ?? (string) ($row['status'] ?: 'Sin estado')) ?></span></td>
                                <td data-label="Vigencia"><?= View::e((string) ($row['start_at'] ?: '—')) ?> – <?= View::e((string) ($row['end_at'] ?: '—')) ?></td>
                                <td data-label="Candidatos"><?= (int) $row['candidates'] ?></td>
                                <td data-label="Comprobado"><?= View::e((string) $row['observed_at']) ?></td>
                            <?php elseif ($page === 'performance'): ?>
                                <td data-label="Fecha"><?= View::e((string) $row['observed_on']) ?></td>
                                <td data-label="Cuenta"><?= View::e((string) $row['account_name']) ?></td>
                                <td data-label="Visitas"><?= number_format((int) $row['visits'], 0, ',', '.') ?></td>
                                <td data-label="Órdenes"><?= number_format((int) $row['orders_count'], 0, ',', '.') ?></td>
                                <td data-label="Conversión"><?= $row['conversion_rate'] === null ? 'Sin base' : View::e(number_format((float) $row['conversion_rate'], 2, ',', '.') . ' %') ?></td>
                                <td data-label="Fuente">Visitas ML + órdenes ERP</td>
                            <?php else: ?>
                                <td data-label="Señal"><strong><?= View::e((string) $row['label']) ?></strong><?php if ($row['external_item_id']): ?><small><?= View::e((string) $row['external_item_id']) ?></small><?php endif; ?></td>
                                <td data-label="Sitio"><?= View::e((string) $row['site_id']) ?></td>
                                <td data-label="Categoría"><?= View::e((string) $row['category_id']) ?></td>
                                <td data-label="Posición"><?= (int) $row['rank_position'] ?: '—' ?></td>
                                <td data-label="Tipo"><?= $row['row_type'] === 'trend' ? 'Tendencia de búsqueda' : 'Producto destacado' ?></td>
                                <td data-label="Comprobado"><?= View::e((string) $row['observed_at']) ?></td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($capabilities !== []): ?>
        <details class="card growth-capabilities">
            <summary>Disponibilidad de información por cuenta</summary>
            <div class="insights-capability-list">
                <?php foreach ($capabilities as $capability): ?>
                    <article>
                        <div>
                            <strong><?= View::e(ucfirst(str_replace('_', ' ', (string) $capability['capability']))) ?></strong>
                            <span>Cuenta #<?= (int) $capability['meli_account_id'] ?></span>
                        </div>
                        <span class="status-badge"><?= View::e($statusLabels[$capability['status']] ?? (string) $capability['status']) ?></span>
                        <p><?= View::e((string) ($capability['safe_message'] ?: 'Información comprobada.')) ?></p>
                    </article>
                <?php endforeach; ?>
            </div>
        </details>
    <?php endif; ?>

    <details class="card module-jobs" <?= $rows === [] && $jobs !== [] ? 'open' : '' ?>>
        <summary>Trabajos y progreso</summary>
        <?php if ($jobs === []): ?>
            <div class="empty-state"><p>No existen trabajos programados.</p></div>
        <?php else: ?>
            <div class="module-job-list">
                <?php foreach ($jobs as $job): ?>
                    <?php
                    $total = max(1, (int) $job['progress_total']);
                    $progress = min($total, max(0, (int) $job['progress_current']));
                    $percent = (int) floor(($progress / $total) * 100);
                    ?>
                    <article>
                        <div class="module-job-list__heading">
                            <div>
                                <strong>Actualización #<?= (int) $job['id'] ?></strong>
                                <span><?= View::e($statusLabels[$job['status']] ?? (string) $job['status']) ?> · <?= View::e($stageLabels[$job['stage']] ?? (string) ($job['stage'] ?: 'En espera')) ?></span>
                            </div>
                            <strong><?= $percent ?> %</strong>
                        </div>
                        <progress value="<?= $progress ?>" max="<?= $total ?>"><?= $percent ?> %</progress>
                        <?php if ($job['safe_error_message']): ?><p class="module-job-error"><?= View::e((string) $job['safe_error_message']) ?></p><?php endif; ?>
                        <small>Próxima oportunidad: <?= View::e((string) ($job['next_run_at'] ?: 'cuando cron esté disponible')) ?></small>
                        <?php if (in_array(Auth::role(), ['admin', 'operator'], true)): ?>
                            <form method="post" action="<?= View::e($base) ?>/meli-growth/jobs/action" class="module-job-actions">
                                <input type="hidden" name="_token" value="<?= View::e($csrfToken) ?>">
                                <input type="hidden" name="job_id" value="<?= (int) $job['id'] ?>">
                                <input type="hidden" name="meli_account_id" value="<?= (int) $job['meli_account_id'] ?>">
                                <input type="hidden" name="page" value="<?= View::e($page) ?>">
                                <?php if (in_array($job['status'], ['pending', 'retry'], true)): ?><button class="btn btn-tertiary" name="action" value="pause" type="submit">Pausar</button><?php endif; ?>
                                <?php if (in_array($job['status'], ['pending', 'retry', 'paused'], true)): ?><button class="btn btn-tertiary" name="action" value="cancel" type="submit">Cancelar</button><?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </details>
</section>
