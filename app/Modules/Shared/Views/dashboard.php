<?php
use App\Core\Csrf;
use App\Core\View;
/** @var \App\Core\Modules\ModuleInterface $module */
$base = rtrim(\App\Core\Env::get('APP_URL', ''), '/');
?>
<section class="module-page" data-module="<?= View::e($module->id()) ?>">
    <header class="page-header module-page__header">
        <div>
            <p class="eyebrow">MÓDULO AISLADO · SOLO LECTURA</p>
            <h1><?= View::e($module->label()) ?></h1>
            <p>Snapshots derivados de Mercado Libre. Una falla aquí no detiene órdenes, productos ni catálogos.</p>
        </div>
        <form method="post" action="<?= View::e($base . '/' . $module->id() . '/refresh') ?>">
            <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
            <button class="btn btn-primary" type="submit">Programar actualización</button>
        </form>
    </header>

    <div class="module-status" role="status">
        <strong>Estado:</strong>
        <?= View::e((string) ($state['status'] ?? 'instalación pendiente')) ?>
        <span>Versión del módulo <?= View::e($module->version()) ?></span>
    </div>

    <div class="module-metrics" aria-label="Resumen del módulo">
        <?php foreach ($metrics as $metric): ?>
            <article class="metric-card">
                <span><?= View::e($metric['label']) ?></span>
                <strong><?= number_format((int) $metric['value'], 0, ',', '.') ?></strong>
            </article>
        <?php endforeach; ?>
    </div>

    <section class="card">
        <div class="card-header"><h2>Trabajos recientes</h2></div>
        <?php if ($jobs === []): ?>
            <div class="empty-state"><strong>Aún no hay actualizaciones programadas</strong><p>Use “Programar actualización” para crear un trabajo seguro y reanudable.</p></div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <caption>Últimos trabajos del módulo</caption>
                    <thead><tr><th>Trabajo</th><th>Estado</th><th>Progreso</th><th>Próxima ejecución</th></tr></thead>
                    <tbody>
                    <?php foreach ($jobs as $job): ?>
                        <tr>
                            <td><?= View::e((string) $job['job_type']) ?></td>
                            <td><?= View::e((string) $job['status']) ?></td>
                            <td><?= (int) $job['progress_current'] ?> / <?= (int) $job['progress_total'] ?></td>
                            <td><?= View::e((string) ($job['next_run_at'] ?? '—')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</section>
