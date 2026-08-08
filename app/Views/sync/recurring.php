<?php
use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\DateTimePresenter;

$base = rtrim(Env::get('APP_URL', ''), '/');
$weekdays = ['1'=>'L','2'=>'M','3'=>'X','4'=>'J','5'=>'V','6'=>'S','7'=>'D'];
?>
<div class="page-head">
    <div><a class="muted" href="<?= View::e($base) ?>/sync">← Volver a sincronizaciones</a><h1>Programación automática</h1><p>El lanzador único de Hostinger envía señales; ERP Meli mide el intervalo real y decide cuándo preparar ventas nuevas según estas reglas.</p></div>
  <div class="page-actions"><a class="btn" href="<?= View::e($base) ?>/sync/audit">Auditoría de ventas</a><a class="btn" href="<?= View::e($base) ?>/settings/cron">Estado cron</a></div>
</div>

<section class="alert info">Zona horaria ERP: <strong><?= View::e(DateTimePresenter::timezone()) ?></strong>. Valores por defecto recomendados: 09:00 a 19:00, cada 60 minutos, solape 2 horas.</section>

<form method="post" action="<?= View::e($base) ?>/sync/recurring/save">
  <input type="hidden" name="_token" value="<?= Csrf::token() ?>">
  <section class="panel filter-bar">
    <div class="inline-form">
        <label class="check-row"><input type="checkbox" name="sync_daily_enabled" value="1" <?= $settings->dailyEnabled() ? 'checked' : '' ?>> Mantener ventas al día con el lanzador único</label>
      <div class="field"><label>Preguntas cada</label><select class="input" name="questions_frequency_minutes"><option value="30">30 minutos</option><option value="60">1 hora</option><option value="120">2 horas</option></select></div>
      <button class="btn primary">Guardar programación</button>
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
          <td><label class="check-row"><input type="checkbox" name="rules[<?= $id ?>][enabled]" value="1" <?= (int)($rule['enabled'] ?? 0)===1?'checked':'' ?>> Activo</label></td>
          <td><select class="input compact-input" name="rules[<?= $id ?>][frequency]"><option value="30" <?= (int)($rule['frequency_minutes'] ?? 60)===30?'selected':'' ?>>30 min</option><option value="60" <?= (int)($rule['frequency_minutes'] ?? 60)===60?'selected':'' ?>>1 hora</option><option value="120" <?= (int)($rule['frequency_minutes'] ?? 60)===120?'selected':'' ?>>2 horas</option></select></td>
          <td><input class="input compact-input" type="time" name="rules[<?= $id ?>][start_time]" value="<?= View::e(substr((string)($rule['start_time'] ?? '09:00'),0,5)) ?>"> <input class="input compact-input" type="time" name="rules[<?= $id ?>][end_time]" value="<?= View::e(substr((string)($rule['end_time'] ?? '19:00'),0,5)) ?>"></td>
          <td><?php foreach($weekdays as $value=>$label): ?><label class="check-row" style="display:inline-flex;margin-right:6px"><input type="checkbox" name="rules[<?= $id ?>][weekdays][]" value="<?= $value ?>" <?= in_array($value,$activeDays,true)?'checked':'' ?>> <?= $label ?></label><?php endforeach; ?></td>
          <td><input class="input compact-input" type="number" min="1" max="12" name="rules[<?= $id ?>][overlap_hours]" value="<?= (int)($rule['overlap_hours'] ?? 2) ?>"> h</td>
          <td><label class="check-row"><input type="checkbox" name="rules[<?= $id ?>][allow_outside_hours]" value="1" <?= (int)($rule['allow_outside_hours'] ?? 0)===1?'checked':'' ?>> Permitir</label></td>
          <td><?= View::e($rule['last_result'] ?: '—') ?><br><small class="muted">Próximo UTC: <?= View::e($rule['next_due_at'] ?: '—') ?></small></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </section>
</form>
