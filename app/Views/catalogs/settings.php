<?php
use App\Core\Env;
use App\Core\View;

$base = rtrim((string) Env::get('APP_URL', ''), '/');
?>
<div class="page-head">
  <div>
    <a class="muted" href="<?= View::e($base) ?>/catalogs">← Volver a catálogos</a>
    <h1>Configuración de catálogos</h1>
    <p>Parámetros globales creados por migración. Para cambios finos use Configuración del sistema.</p>
  </div>
</div>

<section class="panel panel-body">
  <div class="detail-grid">
    <div class="detail-block">
      <h3>Tracking</h3>
      <div class="detail-list">
        <div><span>Estado</span><strong><?= $trackingEnabled ? 'Activo' : 'Inactivo por defecto' ?></strong></div>
        <div><span>Retención</span><strong><?= (int) $viewsRetentionDays ?> días</strong></div>
      </div>
    </div>
    <div class="detail-block">
      <h3>Catálogo público</h3>
      <div class="detail-list"><div><span>Productos por página</span><strong><?= (int) $publicPageSize ?></strong></div></div>
    </div>
    <div class="detail-block">
      <h3>Catálogo privado</h3>
      <div class="detail-list"><div><span>Productos por página</span><strong><?= (int) $privatePageSize ?></strong></div></div>
    </div>
    <div class="detail-block">
      <h3>Stock por origen</h3>
      <div class="detail-list">
        <div><span>Consulta multi-origen</span><strong><?= $stockLocationsEnabled ? 'Activa' : 'Desactivada por seguridad' ?></strong></div>
        <div><span>Lote máximo</span><strong><?= (int) $stockLocationsBatchLimit ?> publicaciones</strong></div>
      </div>
      <p class="muted">Actívela solo después de confirmar permisos para <code>/user-products/{id}/stock</code>. El catálogo público nunca hace esta consulta.</p>
    </div>
  </div>
  <div class="alert info">El catálogo público nunca consulta Mercado Libre en vivo; usa snapshots locales ya sincronizados.</div>
</section>
