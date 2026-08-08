<?php
use App\Core\View;

$money = static fn($value): string => $value === null ? '—' : '$ ' . number_format((float) $value, 0, ',', '.');
$shippingLabels = static function (array $item): string {
    $decoded = json_decode((string) ($item['shipping_methods_json'] ?? '[]'), true);
    if (is_array($decoded) && $decoded !== []) {
        return implode(', ', array_filter(array_map(static fn($row) => (string) ($row['label'] ?? ''), $decoded)));
    }
    return (int) ($item['is_full'] ?? 0) === 1 ? 'FULL confirmado' : 'Logística no identificada';
};
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title><?= View::e($catalog['name']) ?> · impresión privada</title>
  <style>
    body{font-family:Arial,sans-serif;color:#14213d;margin:22px}h1{margin:0 0 6px}.muted{color:#667}
    .grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.card{border:1px solid #d9e1ec;border-radius:10px;padding:12px;break-inside:avoid}
    img{width:90px;height:90px;object-fit:contain;float:left;margin-right:12px}.badge{display:inline-block;background:#eef3fa;border-radius:5px;padding:3px 6px;font-size:11px;margin:2px}
    @media print{button{display:none}.grid{grid-template-columns:repeat(2,1fr)}}
  </style>
</head>
<body>
  <button onclick="window.print()">Imprimir</button>
  <h1><?= View::e($catalog['name']) ?></h1>
  <p class="muted">Impresión privada generada con datos locales. Total: <?= (int) $items['total'] ?> productos.</p>
  <div class="grid">
    <?php foreach ($items['items'] as $item): ?>
      <article class="card">
        <?php if (!empty($item['thumbnail_url'])): ?><img src="<?= View::e($item['thumbnail_url']) ?>" alt=""><?php endif; ?>
        <h3><?= View::e($item['title_snapshot']) ?></h3>
        <p>
          Fuente: <?= ($item['source_type'] ?? 'meli') === 'internal' ? 'Bodega interna' : 'Mercado Libre' ?><br>
          <?= ($item['source_type'] ?? 'meli') === 'internal' ? 'Producto interno' : 'ID ML' ?>: <?= View::e($item['external_item_id']) ?><br>
          Cuenta/empresa: <?= View::e($item['account_name'] ?: ($item['company_name'] ?: '—')) ?><br>
          SKU: <?= View::e($item['sku_snapshot'] ?: '—') ?>
        </p>
        <p><strong><?= View::e($money($item['price_snapshot'])) ?></strong> · Stock <?= $item['stock_available'] === null ? '—' : (int) $item['stock_available'] ?></p>
        <?php if (($item['source_type'] ?? 'meli') === 'meli'): ?>
          <p>FULL <?= $item['stock_full'] === null ? '—' : (int) $item['stock_full'] ?> · No FULL/local <?= $item['stock_non_full'] === null ? '—' : (int) $item['stock_non_full'] ?></p>
        <?php endif; ?>
        <span class="badge"><?= View::e($item['status'] ?? '—') ?></span>
        <?php if (($item['source_type'] ?? 'meli') === 'meli'): ?><span class="badge"><?= View::e($shippingLabels($item)) ?></span><?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</body>
</html>
