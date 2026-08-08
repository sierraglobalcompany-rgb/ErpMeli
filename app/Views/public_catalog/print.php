<?php
use App\Core\View;

$money = static fn($value): string => $value === null ? 'Consultar' : '$ ' . number_format((float) $value, 0, ',', '.');
$showStockDetail = (int) ($catalog['show_stock_detail_public'] ?? 0) === 1;
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="robots" content="noindex,nofollow">
  <title><?= View::e($catalog['name']) ?> · impresión</title>
  <style>
    body{font-family:Arial,sans-serif;color:#222;margin:22px}button{margin-bottom:14px}.grid{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}
    .card{border:1px solid #ddd;border-radius:10px;padding:12px;break-inside:avoid}.card img{width:100%;height:150px;object-fit:contain}.price{font-size:20px;font-weight:700}
    @media print{button{display:none}.grid{grid-template-columns:repeat(2,1fr)}}
  </style>
</head>
<body>
  <button onclick="window.print()">Imprimir</button>
  <h1><?= View::e($catalog['name']) ?></h1>
  <p><?= (int) $items['total'] ?> productos.</p>
  <div class="grid">
    <?php foreach ($items['items'] as $item): ?>
      <article class="card">
        <?php if (!empty($item['thumbnail_url'])): ?><img src="<?= View::e($item['thumbnail_url']) ?>" alt="<?= View::e($item['title_snapshot']) ?>"><?php endif; ?>
        <h3><?= View::e($item['title_snapshot']) ?></h3>
        <?php if ((int) ($catalog['show_prices'] ?? 1) === 1): ?><div class="price"><?= View::e($money($item['price_snapshot'])) ?></div><?php endif; ?>
        <?php if ((int) ($catalog['show_stock'] ?? 1) === 1): ?><p><?= (int) $item['stock_available'] > 0 ? 'Stock disponible' : 'Sin stock' ?></p><?php endif; ?>
        <?php if ((int) ($catalog['show_stock'] ?? 1) === 1 && $showStockDetail): ?><p>FULL: <?= $item['stock_full'] === null ? '—' : (int) $item['stock_full'] ?> · No FULL/local: <?= $item['stock_non_full'] === null ? '—' : (int) $item['stock_non_full'] ?></p><?php endif; ?>
      </article>
    <?php endforeach; ?>
  </div>
</body>
</html>
