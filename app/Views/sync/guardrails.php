<?php
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;
$base = rtrim(Env::get('APP_URL',''),'/');
?>
<div class="page-head"><div><a class="muted" href="<?= View::e($base) ?>/sync">← Volver a sincronizaciones</a><h1>Protección anti-bloqueos ML</h1><p>Circuitos abiertos por rate limit, permisos o señales de bloqueo.</p></div><div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/logs?type=api&http_status=429">Ver 429</a></div></div>
<section class="alert warning">Si aparece una cuenta pausada aquí, ERP Meli no hará más consultas a ese endpoint hasta que pase el enfriamiento.</section>
<section class="panel table-panel">
  <header class="panel-head"><h2>Circuitos abiertos</h2></header>
  <div class="table-scroll"><table class="data-table"><thead><tr><th>Cuenta</th><th>Endpoint</th><th>Motivo</th><th>HTTP</th><th>Abierto</th><th>Pausado hasta</th><th>Mensaje</th></tr></thead><tbody>
  <?php if(!$circuits): ?><tr><td colspan="7"><div class="empty">No hay circuitos abiertos. La integración está operando sin pausas de protección.</div></td></tr><?php endif; ?>
  <?php foreach($circuits as $c): ?><tr><td><?= View::e($c['account_name'] ?: 'Todas') ?></td><td><?= View::e($c['endpoint_path']) ?></td><td><span class="badge red"><?= View::e($c['reason']) ?></span></td><td><?= View::e((string)($c['http_status'] ?: '—')) ?></td><td><?= View::e(DateTimePresenter::format($c['opened_at'])) ?></td><td><?= View::e(DateTimePresenter::format($c['blocked_until'])) ?></td><td><?= View::e($c['last_message'] ?: '—') ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>
