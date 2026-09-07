<?php

use App\Core\Csrf;
use App\Core\Env;
use App\Core\View;
use App\Services\CapacityPolicyService;

$base = rtrim(Env::get('APP_URL', ''), '/');
$rhythm = is_array($rhythm ?? null) ? $rhythm : [];
$capacity = $capacity ?? (new CapacityPolicyService())->snapshot('automation');
$maxCalls = (int) $capacity['current'];
$maxCallsSource = 'Configuración automática guardada';
$formatRamp = static function (mixed $steps, string $fallback = '15 → 20 → 25 → 30 → 35 → 40'): string {
    if (is_array($steps)) {
        $values = array_values(array_filter(array_map('intval', $steps), static fn (int $v): bool => $v > 0));
        return $values === [] ? $fallback : implode(' → ', $values);
    }
    $text = trim((string) $steps);
    if ($text === '') {
        return $fallback;
    }
    $parts = array_values(array_filter(array_map('intval', preg_split('/[^0-9]+/', $text) ?: []), static fn (int $v): bool => $v > 0));
    return $parts === [] ? $fallback : implode(' → ', $parts);
};
$rampInput = static function (mixed $steps, string $fallback = '15,20,25,30,35,40'): string {
    if (is_array($steps)) {
        $values = array_values(array_filter(array_map('intval', $steps), static fn (int $v): bool => $v > 0));
        return $values === [] ? $fallback : implode(',', $values);
    }
    $text = trim((string) $steps);
    if ($text === '') {
        return $fallback;
    }
    $parts = array_values(array_filter(array_map('intval', preg_split('/[^0-9]+/', $text) ?: []), static fn (int $v): bool => $v > 0));
    return $parts === [] ? $fallback : implode(',', $parts);
};
$storedProfile = (string) ($rhythm['profile'] ?? $rhythm['mode'] ?? 'fast');
$profile = match ($storedProfile) {
    'recovery' => 'fast',
    'custom' => match ((int) ($rhythm['target_http_per_minute'] ?? $rhythm['calls_per_block'] ?? 30)) {
        10 => 'conservative', 20 => 'balanced', 40 => 'maximum', default => 'fast',
    },
    default => $storedProfile,
};
$profiles = [
    // Contrato legado 2.28.33 conservado para auditorías estáticas:
    // 'conservative' => ['Conservador', 10
    // 'balanced' => ['Equilibrado', 20
    // 'fast' => ['Rápido', 30
    'conservative' => ['Seguro', 10, '5 → 10', 'Para empezar con el menor impacto posible.'],
    'balanced' => ['Recuperación gradual', 20, '10 → 15 → 20', 'Buen equilibrio entre recuperación y protección.'],
    'fast' => ['Recuperación agresiva', 30, '15 → 20 → 25 → 30', 'Más recuperación con vigilancia automática.'],
    'maximum' => ['Máximo controlado', 40, '15 → 20 → 25 → 30 → 35 → 40', 'Techo alto; solo se alcanza si la evidencia lo permite.'],
    'custom' => ['Personalizado avanzado', max(1, (int) ($rhythm['target_http_per_minute'] ?? 40)), $formatRamp($rhythm['ramp_steps'] ?? '15,20,25,30,35,40'), 'Permite ajustar escalones y evidencia, sin saltar protecciones duras.'],
];
if (!isset($profiles[$profile])) {
    $profile = 'fast';
}
$targetRpm = max(1, (int) ($rhythm['target_http_per_minute'] ?? $profiles[$profile][1]));
$adaptiveLimit = isset($rhythm['current_adaptive_limit'])
    ? max(0, (float) $rhythm['current_adaptive_limit'])
    : (isset($rhythm['ramp_limit_rpm']) ? max(0, (float) $rhythm['ramp_limit_rpm']) : null);
$allowedRpm = isset($rhythm['allowed_rpm'])
    ? max(0, (float) $rhythm['allowed_rpm'])
    : (isset($rhythm['effective_rpm']) ? max(0, (float) $rhythm['effective_rpm']) : null);
$observed15 = isset($rhythm['observed_http_15m']) ? max(0, (float) $rhythm['observed_http_15m']) : null;
$observed60 = isset($rhythm['observed_http_60m']) ? max(0, (float) $rhythm['observed_http_60m']) : null;
$resourcesPerHttp = isset($rhythm['resources_per_http']) ? max(0, (float) $rhythm['resources_per_http']) : null;
$operationalBacklog = isset($rhythm['operational_backlog']) ? max(0, (int) $rhythm['operational_backlog']) : null;
$reviewBacklog = isset($rhythm['review_backlog']) ? max(0, (int) $rhythm['review_backlog']) : null;
$completedLastHour = isset($rhythm['completed_last_hour']) ? max(0, (int) $rhythm['completed_last_hour']) : null;
$rateLimitIncidents = array_values(array_filter(
    is_array($rhythm['recent_rate_limit_incidents'] ?? null) ? $rhythm['recent_rate_limit_incidents'] : [],
    static fn(array $incident): bool => ($incident['transport_class'] ?? '') === 'REMOTE_HTTP_429'
));
$last429 = $rateLimitIncidents[0] ?? null;
$last429SeenAt = is_array($last429) ? trim((string) ($last429['last_seen_at'] ?? '')) : '';
$last429NextSafeAt = is_array($last429) ? trim((string) ($last429['next_safe_at'] ?? $rhythm['next_safe_at'] ?? '')) : trim((string) ($rhythm['next_safe_at'] ?? ''));
$limitingScope = trim((string) ($rhythm['limiting_scope'] ?? ''));
$increaseBlocker = trim((string) ($rhythm['increase_blocker'] ?? ''));
$safeToIncrease = $increaseBlocker === '' && $allowedRpm !== null && $observed60 !== null && $observed60 >= min($allowedRpm, $targetRpm) * 0.7;
$fmtRate = static fn(?float $value): string => $value === null
    ? 'Por medir'
    : number_format($value, 1, ',', '.') . ' HTTP/min';
$billing429Backoff = is_array($rhythm['billing_429_backoff_minutes'] ?? null) ? $rhythm['billing_429_backoff_minutes'] : [];
$billing429First = max(5, min(720, (int) ($billing429Backoff[1] ?? $rhythm['billing_429_backoff_1_minutes'] ?? 30)));
$billing429Second = max($billing429First, max(5, min(720, (int) ($billing429Backoff[2] ?? $rhythm['billing_429_backoff_2_minutes'] ?? 120))));
$billing429Third = max($billing429Second, max(5, min(720, (int) ($billing429Backoff[3] ?? $rhythm['billing_429_backoff_3_minutes'] ?? 360))));
$billing429Max = max($billing429Third, max(5, min(720, (int) ($billing429Backoff[4] ?? $rhythm['billing_429_backoff_max_minutes'] ?? 720))));
$billingIntervalSeconds = max(1, min(3600, (int) ($rhythm['billing_min_interval_seconds'] ?? 300)));
?>
<div class="page-head cron-page-head">
  <div>
    <span class="eyebrow">AUTOMATIZACIÓN · CALIBRACIÓN</span>
    <h1>Automatización y seguridad API</h1>
    <p>Configure el presupuesto físico del ciclo automático sin mezclarlo con ajustes técnicos de ritmo.</p>
  </div>
  <a class="btn" href="<?= View::e($base) ?>/settings/cron">Volver a Cron</a>
</div>

<?php $automationTab = 'rhythm'; require __DIR__ . '/_automation_nav.php'; ?>

<section class="operation-explainer" aria-label="Cómo funciona el ritmo">
  <div><span>Qué está configurado</span><strong data-rhythm-current-profile><?= $maxCalls ?> llamada<?= $maxCalls === 1 ? '' : 's' ?> API/ciclo</strong></div>
  <div><span>Qué hará el ERP</span><strong>Detendrá el ciclo al consumir ese presupuesto físico o ante 429 remoto.</strong></div>
  <div><span>Qué debe saber</span><strong>El cron puede correr cada minuto, pero la seguridad 429 manda sobre la velocidad.</strong></div>
</section>

<section class="rhythm-truth-grid rhythm-capacity-grid" aria-label="Capacidad solicitada, permitida y observada">
  <article>
    <span>Máximo de llamadas API por ciclo automático</span>
    <strong data-rhythm-requested><?= $maxCalls ?> llamada<?= $maxCalls === 1 ? '' : 's' ?></strong>
    <p>Techo configurado: <?= (int) $capacity['ceiling'] ?> llamadas físicas por ciclo.</p>
  </article>
  <article>
    <span>Ritmo Billing</span>
    <strong>1 llamada cada <?= $billingIntervalSeconds ?> segundos</strong>
    <p>Compartido por automático y manual; no cambia los presupuestos.</p>
  </article>
  <article>
    <span>Máximo teórico de 15 minutos</span>
    <strong><?= $maxCalls * 15 ?> llamadas</strong>
    <p>Techo teórico; 429 remoto o pausas locales pueden reducirlo.</p>
  </article>
  <article>
    <span>Fuente efectiva</span>
    <strong><?= View::e($maxCallsSource) ?></strong>
    <p>Presupuesto actual y techo independientes del procesamiento manual.</p>
  </article>
  <article>
    <span>Último 429 / próxima hora segura</span>
    <strong><?= $last429SeenAt !== '' ? View::e($last429SeenAt) : 'Sin 429 reciente' ?></strong>
    <p><?= $last429NextSafeAt !== '' ? 'Próxima hora segura: ' . View::e($last429NextSafeAt) : 'Sin pausa 429 activa en esta lectura.' ?></p>
  </article>
  <article>
    <span>Puerta de aumento</span>
    <strong><?= $safeToIncrease ? 'Disponible' : 'Bloqueada' ?></strong>
    <p><?= $safeToIncrease ? 'No hay bloqueo visible en esta lectura.' : View::e($increaseBlocker !== '' ? $increaseBlocker : 'Requiere más evidencia estable antes de subir.') ?></p>
  </article>
</section>

<section class="api-command-section rhythm-rate-limit-panel" aria-label="Rate limits recientes">
  <header>
    <div>
      <span class="eyebrow">Protección Mercado Libre</span>
      <h2>Rate limit 429 recientes</h2>
      <p>Un 429 no significa bloqueo definitivo, pero sí obliga a respetar la espera y no subir ritmo sin estabilidad.</p>
    </div>
    <a class="btn" href="<?= View::e($base) ?>/settings/api-health/incidents?http_status=429&amp;origin=remote">Ver incidentes 429</a>
  </header>
  <?php if ($rateLimitIncidents === []): ?>
    <p class="api-inline-empty is-success">Sin 429 recientes en las últimas 24 horas para las cuentas autorizadas.</p>
  <?php else: ?>
    <div class="api-attention-list">
      <?php foreach ($rateLimitIncidents as $incident): ?>
        <a href="<?= View::e($base) ?>/settings/api-health/incidents/show?key=<?= View::e((string) $incident['incident_key']) ?>">
          <strong><?= View::e((string) ($incident['account_names'] ?: 'Aplicación')) ?> · <?= View::e((string) ($incident['operation_label'] ?? 'Operación API')) ?></strong>
          <span><?= number_format((int) ($incident['repetitions'] ?? 0), 0, ',', '.') ?> repeticiones · última vez <?= View::e((string) ($incident['last_seen_at'] ?? 'por comprobar')) ?> · activo ahora: <?= !empty($incident['active_now']) ? 'Sí' : 'No' ?></span>
          <b>Revisar protección</b>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>

<section class="card rhythm-editor" data-rhythm-editor data-saved-profile="<?= View::e($profile) ?>">
  <div class="card-header">
      <div><h2>Presupuesto del ciclo automático</h2><p>Configure el techo permitido y el presupuesto actual por separado.</p></div>
  </div>
  <form id="call-budget-form" method="post" action="<?= View::e($base) ?>/settings/cron/call-budget">
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <input type="hidden" name="capacity_revision" value="<?= View::e($capacity['revision']) ?>">
    <section class="rhythm-custom-panel" aria-label="Presupuesto físico por ciclo">
      <div class="rhythm-panel-head">
        <div>
          <span class="eyebrow">K1D · UNIDAD CANÓNICA</span>
          <h3>Máximo de llamadas API físicas por ciclo</h3>
          <p>El valor seguro recomendado es 1. Subirlo requiere evidencia estable y sin 429 remoto reciente.</p>
        </div>
      </div>
      <label class="field"><span>Techo permitido de llamadas API por ciclo</span><input class="input" type="number" name="automation_api_calls_ceiling" min="1" max="100" value="<?= (int) $capacity['ceiling'] ?>" required><small>Inicial: 55. Límite técnico: 100. Subir el techo no cambia el presupuesto actual.</small></label>
      <label class="field"><span>Llamadas API por ciclo automático</span><input class="input" type="number" name="automation_max_api_calls_per_cycle" min="1" max="100" value="<?= $maxCalls ?>" required><small>Debe ser menor o igual al techo elegido. No equivale a cantidad de órdenes ni recursos.</small></label>
      <div class="alert info">
        <strong>El cron recomendado queda:</strong>
        <code>jobs/queue_v4_clean.php --runtime=45</code>
        <small>Overrides técnicos disponibles sólo para soporte avanzado: <code>--max-calls=N</code> puede reducir lo configurado. La unidad <code>--max-jobs</code> fue retirada; configure llamadas físicas en el ERP.</small>
      </div>
      <div class="page-actions mt-2">
        <button class="btn primary" type="submit">Revisar y guardar presupuesto</button>
      </div>
    </section>
  </form>
  <details class="panel settings-advanced rhythm-advanced-settings">
    <summary><span><strong>Ajustes avanzados</strong><small>Perfiles HTTP, rampa, ventanas, jitter, backoff Billing y controles heredados.</small></span><span aria-hidden="true">⌄</span></summary>
  <form id="rhythm-profile-form" method="post" action="<?= View::e($base) ?>/settings/cron/rhythm">
    <input type="hidden" name="_token" value="<?= View::e(Csrf::token()) ?>">
    <fieldset class="rhythm-profile-fieldset"><legend>Perfil de velocidad HTTP</legend>
    <p class="rhythm-step-title">1. Velocidad máxima deseada</p>
    <div class="rhythm-profile-grid rhythm-profile-grid-four">
      <?php foreach ($profiles as $key => [$label, $target, $ramp, $description]): ?>
        <label class="rhythm-profile <?= $profile === $key ? 'is-selected' : '' ?>" data-rhythm-target="<?= $target ?>" data-rhythm-ramp="<?= View::e($ramp) ?>">
          <input type="radio" name="profile" value="<?= View::e($key) ?>" <?= $profile === $key ? 'checked' : '' ?>>
          <strong><?= View::e($label) ?></strong>
          <b><?= $target ?> HTTP/min</b>
          <span><?= View::e($description) ?></span>
          <small>Rampa: <?= View::e($ramp) ?></small>
        </label>
      <?php endforeach; ?>
    </div></fieldset>

    <div class="rhythm-selection-preview" data-rhythm-selection-preview aria-live="polite">
      <div><span>Perfil elegido</span><strong data-rhythm-preview-profile><?= View::e($profiles[$profile][0]) ?></strong></div>
      <div><span>Techo solicitado</span><strong data-rhythm-preview-target><?= $targetRpm ?> HTTP/min</strong></div>
      <div><span>Rampa segura</span><strong data-rhythm-preview-ramp><?= View::e($profiles[$profile][2]) ?></strong></div>
      <p data-rhythm-preview-note>Configuración actualmente guardada. El ritmo real puede ser menor que el techo.</p>
    </div>

    <label class="check rhythm-adaptive"><input type="checkbox" name="adaptive_enabled" <?= !array_key_exists('adaptive_enabled', $rhythm) || !empty($rhythm['adaptive_enabled']) ? 'checked' : '' ?>> Mantener la rampa adaptativa y reducir automáticamente ante señales de riesgo</label>
    <section class="rhythm-custom-panel" aria-label="Rampa personalizada">
      <div class="rhythm-panel-head">
        <div>
          <span class="eyebrow">PERSONALIZADO AVANZADO</span>
          <h3>Editar rampa personalizada</h3>
          <p>Use esto solo si quiere ajustar cómo sube Cron. Las protecciones duras siempre mandan.</p>
        </div>
      </div>
      <div class="rhythm-wizard-grid">
        <article class="rhythm-wizard-card">
          <span class="step-pill">2</span>
          <h4>Cómo subir gradualmente</h4>
          <label><span>Máximo que quiere permitir</span><input type="number" name="custom_target_http_per_minute" min="1" max="300" value="<?= (int) $targetRpm ?>"><small>Es un techo, no una promesa.</small></label>
          <label><span>Subir por estos escalones</span><input type="text" name="custom_ramp_steps" value="<?= View::e($rampInput($rhythm['ramp_steps'] ?? [15,20,25,30,35,40])) ?>"><small>Ejemplo: 10,15,20,25,30</small></label>
          <label><span>Tiempo mínimo antes de subir</span><span class="input-with-unit"><input type="number" name="ramp_evaluation_minutes" min="5" max="1440" value="<?= (int) ($rhythm['ramp_evaluation_minutes'] ?? 1440) ?>"><em>min</em></span><small>Recomendado: 1440 min = 24 horas.</small></label>
        </article>
        <article class="rhythm-wizard-card">
          <span class="step-pill">3</span>
          <h4>Condiciones de seguridad</h4>
          <label><span>Mínimo de respuestas exitosas</span><input type="number" name="ramp_min_known_responses" min="1" max="10000" value="<?= (int) ($rhythm['ramp_min_known_responses'] ?? 60) ?>"></label>
          <label><span>HTTP 429 permitidos</span><input type="number" name="ramp_max_429" min="0" max="100" value="<?= (int) ($rhythm['ramp_max_429'] ?? 0) ?>"></label>
          <label><span>Leases perdidos permitidos</span><input type="number" name="ramp_max_lease_lost" min="0" max="100" value="<?= (int) ($rhythm['ramp_max_lease_lost'] ?? 0) ?>"></label>
          <label><span>Duplicados permitidos</span><input type="number" name="ramp_max_duplicates" min="0" max="100" value="<?= (int) ($rhythm['ramp_max_duplicates'] ?? 0) ?>"></label>
          <label><span>Tiempo máximo normal por consulta</span><span class="input-with-unit"><input type="number" name="ramp_p95_http_ms" min="500" max="60000" value="<?= (int) ($rhythm['ramp_p95_http_ms'] ?? 5000) ?>"><em>ms</em></span></label>
        </article>
        <article class="rhythm-wizard-card rhythm-simulation-card">
          <span class="step-pill">✓</span>
          <h4>Simulación antes de guardar</h4>
          <dl class="rhythm-simulation-list">
            <div><dt>Solicitado</dt><dd><?= $targetRpm ?> HTTP/min</dd></div>
            <div><dt>Rampa actual</dt><dd><?= $adaptiveLimit === null ? 'Por comprobar' : View::e(number_format($adaptiveLimit, 1, ',', '.')) . ' HTTP/min' ?></dd></div>
            <div><dt>Permitido real</dt><dd><?= View::e($fmtRate($allowedRpm)) ?></dd></div>
            <div><dt>Observado real</dt><dd><?= View::e($fmtRate($observed60)) ?></dd></div>
            <div><dt>Impacto esperado</dt><dd><?= $allowedRpm === null ? 'Por medir' : View::e(number_format($allowedRpm * 60, 0, ',', '.')) . ' HTTP/h como techo efectivo actual' ?></dd></div>
            <div><dt>Qué impide subir</dt><dd><?= $safeToIncrease ? 'Sin bloqueo visible en esta lectura.' : View::e($increaseBlocker !== '' ? $increaseBlocker : 'Cron necesita más evidencia completa y backlog ejecutable bajando.') ?></dd></div>
          </dl>
        </article>
      </div>
      <label class="check"><input type="checkbox" name="ramp_require_drainage" <?= !array_key_exists('ramp_require_drainage', $rhythm) || !empty($rhythm['ramp_require_drainage']) ? 'checked' : '' ?>> Exigir que el backlog ejecutable baje antes de subir la rampa</label>
    </section>
    <section class="rhythm-custom-panel" aria-label="Protección ante errores peligrosos de Mercado Libre">
      <div class="rhythm-panel-head">
        <div>
          <span class="eyebrow">BILLING 429</span>
          <h3>Ritmo Billing y protección ante errores peligrosos</h3>
          <p>El intervalo controla cuándo puede salir el siguiente GET de Billing. Las pausas 429 y Retry-After siempre prevalecen.</p>
        </div>
        <a class="btn" href="<?= View::e($base) ?>/settings/api-health/protection">Ver salud API</a>
      </div>
      <label class="field">
        <span>Ritmo Billing: 1 llamada cada N segundos</span>
        <span class="input-with-unit"><input type="number" name="billing_min_interval_seconds" min="1" max="3600" value="<?= $billingIntervalSeconds ?>" required><em>seg</em></span>
        <small>Default: 300. Reducirlo exige salud certificada. No modifica el techo ni el presupuesto de trabajo.</small>
      </label>
      <div class="rhythm-wizard-grid">
        <article class="rhythm-wizard-card">
          <span class="step-pill">1</span>
          <h4>1er Billing 429</h4>
          <label><span>Pausa</span><span class="input-with-unit"><input type="number" name="billing_429_backoff_1_minutes" min="5" max="720" value="<?= $billing429First ?>"><em>min</em></span><small>Default KISS: 30 min.</small></label>
        </article>
        <article class="rhythm-wizard-card">
          <span class="step-pill">2</span>
          <h4>2do Billing 429</h4>
          <label><span>Pausa</span><span class="input-with-unit"><input type="number" name="billing_429_backoff_2_minutes" min="5" max="720" value="<?= $billing429Second ?>"><em>min</em></span><small>Default KISS: 120 min.</small></label>
        </article>
        <article class="rhythm-wizard-card">
          <span class="step-pill">3</span>
          <h4>3er Billing 429</h4>
          <label><span>Pausa</span><span class="input-with-unit"><input type="number" name="billing_429_backoff_3_minutes" min="5" max="720" value="<?= $billing429Third ?>"><em>min</em></span><small>Default KISS: 360 min.</small></label>
        </article>
        <article class="rhythm-wizard-card">
          <span class="step-pill">4+</span>
          <h4>4+ Billing 429</h4>
          <label><span>Pausa máxima</span><span class="input-with-unit"><input type="number" name="billing_429_backoff_max_minutes" min="5" max="720" value="<?= $billing429Max ?>"><em>min</em></span><small>Default KISS: 720 min = 12 horas.</small></label>
        </article>
      </div>
      <div class="alert info">
        <strong>No reduce Retry-After enviado por Mercado Libre.</strong>
        Sólo aplica a Billing 429 remoto real; las demoras locales preventivas se reportan aparte como <code>LOCAL_RATE_LIMITED_PRETRANSPORT</code>.
      </div>
    </section>
    <div class="alert info"><strong>La cifra no significa “datos por minuto”.</strong> Solo cuenta cuando comienza el transporte HTTP. Seleccionar, inspeccionar o aplazar un trabajo no consume el límite.</div>
    <div class="page-actions rhythm-save-actions">
      <button class="btn primary" type="submit">Guardar velocidad</button>
      <a class="btn" href="<?= View::e($base) ?>/settings/cron">Cancelar</a>
    </div>
    <noscript><p class="alert warning">Puede guardar el perfil sin JavaScript. La confirmación visual se actualizará al recargar.</p></noscript>
  </form>
  </details>
</section>
