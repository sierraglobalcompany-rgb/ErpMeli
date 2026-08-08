<?php
use App\Core\Env;
use App\Core\View;
$base = rtrim(Env::get('APP_URL', ''), '/');
$events = $migrationDiagnostic['events'] ?? [];
$failure = $migrationDiagnostic['latest_failure'] ?? null;
$lock = $migrationDiagnostic['lock'] ?? null;
$metadata = $migrationDiagnostic['first_pending_metadata'] ?? null;
$recovery = $migrationDiagnostic['recovery'] ?? null;
$page = max(1, (int)($migrationDiagnostic['event_page'] ?? 1));
?>
<div class="page-head">
  <div><h1>Diagnóstico de migraciones</h1><p>Trazabilidad sanitizada y de solo lectura del motor de actualizaciones.</p></div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/settings/diagnostics">Diagnóstico general</a><a class="btn" href="<?= View::e($base) ?>/settings/diagnostics/migrations/export">Exportar JSON</a><a class="btn primary" href="<?= View::e($base) ?>/settings/update">Actualizador</a></div>
</div>

<div class="alert <?= !empty($migrationDiagnostic['safe_to_retry']) ? 'success' : 'warning' ?>">
  <strong><?= !empty($migrationDiagnostic['safe_to_retry']) ? 'Reintento permitido por diagnóstico.' : 'Revise antes de reintentar.' ?></strong>
  <?= View::e((string)($migrationDiagnostic['recommendation'] ?? '')) ?>
</div>
<?php if (is_array($recovery)): ?>
<section class="panel">
  <header class="panel-head"><div><h2>Recuperación certificada de 087</h2><p>Validación de solo lectura antes de ejecutar cualquier SQL.</p></div>
    <span class="badge <?= !empty($recovery['authorized']) ? 'green' : 'red' ?>"><?= !empty($recovery['authorized']) ? 'Autorizada' : 'Bloqueada' ?></span>
  </header>
  <div class="module-queue-health__metrics" aria-label="Comprobaciones de recuperación">
    <?php
    $recoveryChecks = [
      'source_failure_found' => 'Fallo original encontrado',
      'partial_schema_valid' => 'Fingerprint parcial válido',
      'corrected_sql_not_started' => 'SQL corregido no iniciado',
      'no_active_module_job' => 'Sin lease modular activo',
      'not_applied' => '087 todavía no aplicada',
    ];
    foreach ($recoveryChecks as $key => $label):
      $passed = !empty($recovery['checks'][$key]);
    ?>
      <span><strong><?= $passed ? '✓' : '—' ?></strong><?= View::e($label) ?></span>
    <?php endforeach; ?>
  </div>
  <?php if (empty($recovery['authorized'])): ?>
    <div class="alert danger">Motivo seguro: <?= View::e((string)($recovery['reason'] ?? 'condición no autorizada')) ?>. No se ejecutará SQL.</div>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="metrics">
  <?php foreach ([
    ['Migraciones', (int)($migrationDiagnostic['file_count'] ?? 0)],
    ['Aplicadas', (int)($migrationDiagnostic['applied_count'] ?? 0)],
    ['Pendientes', (int)($migrationDiagnostic['pending_count'] ?? 0)],
    ['Primera pendiente', (string)($migrationDiagnostic['first_pending']['migration_key'] ?? 'ninguna')],
  ] as [$label,$value]): ?>
  <article class="metric-card"><div><div class="metric-label"><?= View::e((string)$label) ?></div><div class="metric-value"><?= View::e((string)$value) ?></div></div></article>
  <?php endforeach; ?>
</section>

<section class="panel table-panel mt-2">
  <header class="panel-head"><h2>Estado del migrador</h2></header>
  <div class="table-scroll"><table class="data-table"><tbody>
    <tr><th>ID del último fallo</th><td><code><?= View::e((string)($failure['diagnostic_id'] ?? 'sin registro')) ?></code></td></tr>
    <tr><th>Migración</th><td><?= View::e((string)($failure['migration_key'] ?? '—')) ?></td></tr>
    <tr><th>Etapa</th><td><?= View::e((string)($failure['stage'] ?? '—')) ?></td></tr>
    <tr><th>Referencia segura</th><td><code><?= View::e((string)($failure['diagnostic_id'] ?? 'sin registro')) ?></code></td></tr>
    <tr><th>Mensaje seguro</th><td><?= View::e((string)($failure['safe_message'] ?? '—')) ?></td></tr>
    <tr><th>Checksum esperado</th><td><code><?= View::e((string)($migrationDiagnostic['first_pending']['checksum_sha256'] ?? '—')) ?></code></td></tr>
    <tr><th>Checksum registrado</th><td><code><?= View::e((string)($metadata['checksum_sha256'] ?? '—')) ?></code></td></tr>
    <tr><th>Estado metadata</th><td><?= View::e((string)($metadata['state'] ?? 'sin registro')) ?> · intentos <?= (int)($metadata['attempts'] ?? 0) ?></td></tr>
    <tr><th>Lock</th><td><?= View::e((string)($lock['lock_status'] ?? 'sin lock')) ?><?= !empty($lock['expires_at']) ? ' · vence ' . View::e((string)$lock['expires_at']) . ' UTC' : '' ?></td></tr>
    <tr><th>PDO</th><td><?= View::e((string)($migrationDiagnostic['pdo']['driver'] ?? '—')) ?> · servidor <?= View::e((string)($migrationDiagnostic['pdo']['server_version'] ?? '—')) ?> · prepares emulados <?= !empty($migrationDiagnostic['pdo']['emulated_prepares']) ? 'sí' : 'no' ?></td></tr>
  </tbody></table></div>
</section>

<section class="panel table-panel mt-2">
  <header class="panel-head"><h2>Esquema del motor seguro</h2></header>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Tabla</th><th>Estado</th><th>Collation</th></tr></thead><tbody>
  <?php foreach (($migrationDiagnostic['engine_tables'] ?? []) as $table=>$exists): ?>
    <tr><td><code><?= View::e((string)$table) ?></code></td><td><span class="badge <?= $exists ? 'green' : 'amber' ?>"><?= $exists ? 'presente' : 'pendiente' ?></span></td><td><?= View::e((string)($migrationDiagnostic['collations'][$table] ?? '—')) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <?php if (!empty($migrationDiagnostic['missing_metadata_columns'])): ?><div class="alert warning">Columnas metadata faltantes: <?= View::e(implode(', ', $migrationDiagnostic['missing_metadata_columns'])) ?></div><?php endif; ?>
  <?php if (!empty($migrationDiagnostic['missing_indexes'])): ?><div class="alert warning">Índices faltantes: <?= View::e(implode(', ', $migrationDiagnostic['missing_indexes'])) ?></div><?php endif; ?>
</section>

<section class="panel table-panel mt-2">
  <header class="panel-head"><div><h2>Eventos recientes</h2><p>No contienen SQL, parámetros, tokens ni credenciales.</p></div></header>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Fecha UTC</th><th>Diagnóstico</th><th>Migración</th><th>Etapa</th><th>Estado</th><th>Error</th><th>Contexto</th></tr></thead><tbody>
  <?php if ($events === []): ?><tr><td colspan="7" class="empty">Todavía no hay eventos de migración registrados.</td></tr><?php endif; ?>
  <?php foreach ($events as $event): ?>
    <tr>
      <td><?= View::e((string)($event['created_at'] ?? $event['created_at_utc'] ?? '—')) ?></td>
      <td><code><?= View::e((string)($event['diagnostic_id'] ?? '—')) ?></code></td>
      <td><?= View::e((string)($event['migration_key'] ?? '—')) ?></td>
      <td><?= View::e((string)($event['stage'] ?? '—')) ?></td>
      <td><span class="badge <?= ($event['status'] ?? '') === 'failed' ? 'red' : (($event['status'] ?? '') === 'warning' ? 'amber' : 'green') ?>"><?= View::e((string)($event['status'] ?? '—')) ?></span></td>
      <td><?= View::e((string)($event['safe_message'] ?? 'La causa técnica está disponible mediante el diagnóstico.')) ?></td>
      <td><?php if (!empty($event['context'])): ?><details><summary>Ver</summary><pre><?= View::e((string)json_encode($event['context'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?></pre></details><?php else: ?>—<?php endif; ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
  <footer class="panel-foot page-actions">
    <?php if ($page > 1): ?><a class="btn" href="<?= View::e($base) ?>/settings/diagnostics/migrations?page=<?= $page - 1 ?>">← Anterior</a><?php endif; ?>
    <span>Página <?= $page ?></span>
    <?php if (count($events) === (int)($migrationDiagnostic['event_per_page'] ?? 50)): ?><a class="btn" href="<?= View::e($base) ?>/settings/diagnostics/migrations?page=<?= $page + 1 ?>">Siguiente →</a><?php endif; ?>
  </footer>
</section>
