<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$weekdays = ['1'=>'L','2'=>'M','3'=>'X','4'=>'J','5'=>'V','6'=>'S','7'=>'D'];
?>
<div class="page-head">
    <div><a class="muted" href="<?= View::e($base) ?>/sync">← Volver a sincronizaciones</a><h1>Programación recurrente retirada</h1><p>Estas reglas se conservan como historial, pero ya no crean trabajo automático. Sólo puede guardarlas desactivadas.</p></div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/sync/audit">Auditoría de ventas</a><a class="btn" href="<?= View::e($base) ?>/settings/cron">Estado cron</a></div>
</div>

<section class="alert info">Zona horaria ERP: <strong><?= View::e(DateTimePresenter::timezone()) ?></strong>. Valores por defecto recomendados: 09:00 a 19:00, cada 60 minutos, solape 2 horas.</section>

<form method="post" action="<?= View::e($base) ?>/sync/recurring/save">
  <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
  <section class="panel filter-bar">
    <div class="inline-form">
      <span class="muted">Al guardar, todas las reglas visibles quedarán desactivadas; el historial no se borra.</span>
      <button class="btn primary">Guardar desactivación</button>
    </div>
  </section>

  <section class="panel table-panel">
    <header class="panel-head"><h2>Reglas por cuenta</h2></header>
    <div class="table-scroll"><table class="data-table">
      <thead><tr><th>Cuenta</th><th>Activo</th><th>Frecuencia</th><th>Horario</th><th>Días</th><th>Solape</th><th>Fuera horario</th><th>Último resultado</th></tr></thead>
      <tbody>
      <?php foreach($rules as $rule): $id=(int)$rule['account_id']; $activeDays=explode(',', (string)($rule['active_weekdays'] ?? '1,2,3,4,5,6,7')); ?>
        <tr>
          <td><strong><?= View::e($rule['account_name']) ?></strong><br><small class="muted"><?= View::e($rule['status'] ?? '') ?></small></td>
          <td><span class="badge <?= (int)($rule['enabled'] ?? 0)===1?'amber':'green' ?>"><?= (int)($rule['enabled'] ?? 0)===1?'Se desactivará':'Desactivado' ?></span></td>
          <td><?= (int)($rule['frequency_minutes'] ?? 60) ?> min<input type="hidden" name="rules[<?= $id ?>][frequency]" value="<?= (int)($rule['frequency_minutes'] ?? 60) ?>"></td>
          <td><?= View::e(substr((string)($rule['start_time'] ?? '09:00'),0,5)) ?>–<?= View::e(substr((string)($rule['end_time'] ?? '19:00'),0,5)) ?><input type="hidden" name="rules[<?= $id ?>][start_time]" value="<?= View::e(substr((string)($rule['start_time'] ?? '09:00'),0,5)) ?>"><input type="hidden" name="rules[<?= $id ?>][end_time]" value="<?= View::e(substr((string)($rule['end_time'] ?? '19:00'),0,5)) ?>"></td>
          <td><?php foreach($weekdays as $value=>$label): ?><?php if (in_array($value,$activeDays,true)): ?><span class="badge"><?= $label ?></span><input type="hidden" name="rules[<?= $id ?>][weekdays][]" value="<?= $value ?>"><?php endif; ?><?php endforeach; ?></td>
          <td><?= (int)($rule['overlap_hours'] ?? 2) ?> h<input type="hidden" name="rules[<?= $id ?>][overlap_hours]" value="<?= (int)($rule['overlap_hours'] ?? 2) ?>"></td>
          <td><?= (int)($rule['allow_outside_hours'] ?? 0)===1?'Permitido':'No permitido' ?><?php if ((int)($rule['allow_outside_hours'] ?? 0)===1): ?><input type="hidden" name="rules[<?= $id ?>][allow_outside_hours]" value="1"><?php endif; ?></td>
          <td><?= View::e($rule['last_result'] ?: '—') ?><br><small class="muted">Próximo UTC: <?= View::e($rule['next_due_at'] ?: '—') ?></small></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
</form>
