<?php
// Presentation only: a missing count remains unknown, never a certified zero.
$count = static fn (mixed $value): string => is_int($value) && $value >= 0 ? (string) $value : 'UNKNOWN';
$certainty = (string) ($callResult['evidence_state'] ?? 'UNKNOWN');
$physical = array_key_exists('physical_http_calls', $callResult) ? $callResult['physical_http_calls']
    : ($certainty === 'CERTIFIED' ? ($callResult['api_calls_used'] ?? null) : null);
?>
<div class="metric-grid manual-metric-grid" aria-label="Resultado de llamadas físicas">
  <article><span>Presupuesto configurado</span><strong><?= $count($callResult['configured_api_calls'] ?? null) ?></strong></article>
  <article><span>Llamadas solicitadas</span><strong><?= $count($callResult['requested_api_calls'] ?? null) ?></strong></article>
  <article><span>Presupuesto efectivo</span><strong><?= $count($callResult['effective_api_calls'] ?? null) ?></strong></article>
  <article><span>Llamadas físicas usadas</span><strong><?= $count($physical) ?></strong></article>
  <article><span>Llamadas restantes</span><strong><?= $count($callResult['api_calls_remaining'] ?? null) ?></strong></article>
  <article><span>Certeza de evidencia</span><strong><?= \App\Core\View::e((string) ($callResult['evidence_state'] ?? 'UNKNOWN')) ?></strong></article>
</div>
<?php if ($certainty !== 'CERTIFIED' && isset($callResult['api_calls_used'])): ?>
<p>Capacidad consumida sin devolución: <?= $count($callResult['api_calls_used']) ?>.
Las reservas cuyo envío no pudo certificarse siguen descontadas por seguridad; no prueban una cantidad exacta de llamadas enviadas.</p>
<?php endif; ?>
<p>Motivo de parada: <?= \App\Core\View::e((string) ($callResult['stop_reason'] ?? 'UNKNOWN')) ?>.</p>
<p>Próxima salida permitida: <?= \App\Core\View::e((string) ($callResult['next_allowed_at'] ?? 'Sujeta a revalidación de las protecciones')) ?>.</p>
