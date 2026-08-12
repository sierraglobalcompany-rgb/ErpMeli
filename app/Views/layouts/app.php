<?php
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\AppVersionService;
use App\Services\AssetVersionService;
use App\Services\SystemSafetyStatusService;
use App\Repositories\NavigationRepository;

$base = rtrim(Env::get('APP_URL', ''), '/');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$user = Auth::user();
$selectedCompany = (int) ($_GET['company_id'] ?? 0);
$selectedAccount = (int) ($_GET['account_id'] ?? 0);
$selectedFrom = (string) ($_GET['from'] ?? date('Y-m-01'));
$selectedTo = (string) ($_GET['to'] ?? date('Y-m-d'));
$version = AppVersionService::fileVersion();
$safety = (new SystemSafetyStatusService())->status();
$appCssFingerprint = AssetVersionService::fingerprint('assets/app.css');
$catalogCssFingerprint = AssetVersionService::fingerprint('assets/catalog.css');
$uxCssFingerprint = AssetVersionService::fingerprint('assets/ux.css');
$performanceCssFingerprint = AssetVersionService::fingerprint('assets/performance.css');
$appJsFingerprint = AssetVersionService::fingerprint('assets/app.js');
$catalogJsFingerprint = AssetVersionService::fingerprint('assets/catalog.js');
$uxJsFingerprint = AssetVersionService::fingerprint('assets/ux.js');
$performanceJsFingerprint = AssetVersionService::fingerprint('assets/performance.js');
$moduleRoute = preg_match('#/(?:meli-ads|meli-growth|meli-insights|meli-logistics|meli-postsale)(?:/|$)#', $path) === 1;
$moduleAssets = Auth::check() && $moduleRoute
    ? (new \App\Core\Modules\ModuleKernel())->assets()
    : ['css' => [], 'js' => []];
$icon = static fn(string $name): string => '<svg aria-hidden="true"><use href="'
    . View::e(View::asset($base, 'icons.svg')) . '#' . View::e($name) . '"></use></svg>';

$basePath = rtrim((string) (parse_url($base, PHP_URL_PATH) ?: ''), '/');
$routePath = $path;
if ($basePath !== '' && str_starts_with($routePath, $basePath)) {
    $routePath = substr($routePath, strlen($basePath)) ?: '/';
}
$routePath = '/' . trim($routePath, '/');
if ($routePath === '//') {
    $routePath = '/';
}

$documentTitle = match (true) {
    $routePath === '/' => 'Inicio',
    str_starts_with($routePath, '/sales-control') => 'Control de ventas',
    str_starts_with($routePath, '/sales') => 'Ventas',
    str_starts_with($routePath, '/orders') => 'Órdenes',
    str_starts_with($routePath, '/products/meli') => 'Productos Mercado Libre',
    str_starts_with($routePath, '/inventory') => 'Inventario',
    str_starts_with($routePath, '/products') => 'Productos',
    str_starts_with($routePath, '/financial-recalc') => 'Recálculo financiero',
    str_starts_with($routePath, '/reports/profitability') => 'Rentabilidad',
    str_starts_with($routePath, '/notifications') => 'Notificaciones',
    str_starts_with($routePath, '/sync') => 'Sincronizaciones',
    str_starts_with($routePath, '/settings/cron') => 'Cron',
    str_starts_with($routePath, '/settings/api-health') => 'Salud API',
    str_starts_with($routePath, '/settings/backups') => 'Copias y recuperación',
    str_starts_with($routePath, '/settings/database-maintenance') => 'Saneamiento',
    str_starts_with($routePath, '/settings/modules') => 'Módulos',
    str_starts_with($routePath, '/settings/update') => 'Actualizaciones',
    str_starts_with($routePath, '/settings/manual-processing') => 'Procesar ahora',
    str_starts_with($routePath, '/settings') => 'Configuración',
    default => 'ERP Meli',
};

$navigation = new NavigationRepository();
$navSections = $navigation->sections((string) ($user['role'] ?? ''), Auth::isTemporary());
$contextTabs = $navigation->tabs($routePath);
$isActiveRoute = static fn(array $matches): bool => $navigation->isActive($routePath, $matches);
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="color-scheme" content="light">
  <meta name="csrf-token" content="<?= View::e(Csrf::token()) ?>">
  <meta name="app-base-url" content="<?= View::e($base) ?>">
  <title><?= View::e($documentTitle === 'ERP Meli' ? $documentTitle : $documentTitle . ' · ERP Meli') ?></title>
  <link rel="stylesheet" href="<?= View::e(View::asset($base, 'app.css')) ?>&amp;v=<?= View::e($appCssFingerprint) ?>">
  <link rel="stylesheet" href="<?= View::e(View::asset($base, 'catalog.css')) ?>&amp;v=<?= View::e($catalogCssFingerprint) ?>">
  <link rel="stylesheet" href="<?= View::e(View::asset($base, 'ux.css')) ?>&amp;v=<?= View::e($uxCssFingerprint) ?>">
  <link rel="stylesheet" href="<?= View::e(View::asset($base, 'performance.css')) ?>&amp;v=<?= View::e($performanceCssFingerprint) ?>">
  <?php foreach ($moduleAssets['css'] as $moduleCss): ?><link rel="stylesheet" href="<?= View::e(View::asset($base, preg_replace('#^assets/#', '', $moduleCss) ?: $moduleCss)) ?>&amp;v=<?= View::e(AssetVersionService::fingerprint($moduleCss)) ?>"><?php endforeach; ?>
</head>
<body>
<div class="app-shell">
  <div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>
  <aside class="sidebar" id="sidebar">
    <a class="brand" href="<?= View::e($base) ?>/"><span class="brand-mark">M</span><span>ERP Meli</span></a>
    <nav class="nav" aria-label="Navegación principal">
      <a href="<?= View::e($base) ?>/" class="nav-item <?= $routePath === '/' ? 'active' : '' ?>"><?= $icon('grid') ?><span>Inicio</span></a>
      <?php foreach ($navSections as $section): $sectionKey = $section['key']; ?>
        <?php
          $sectionActive = false;
          foreach ($section['items'] as $navItem) {
              if ($isActiveRoute($navItem['matches'])) {
                  $sectionActive = true;
                  break;
              }
          }
          $sectionId = 'nav-section-' . $sectionKey;
        ?>
        <div class="nav-section <?= $sectionActive ? 'open' : '' ?>" data-nav-section="<?= View::e($sectionKey) ?>">
          <button type="button" class="nav-section-toggle" data-nav-toggle="<?= View::e($sectionKey) ?>" aria-expanded="<?= $sectionActive ? 'true' : 'false' ?>" aria-controls="<?= View::e($sectionId) ?>">
            <?= $icon($section['icon']) ?><span><?= View::e($section['label']) ?></span><span class="nav-chevron" aria-hidden="true">▾</span>
          </button>
          <div class="nav-section-items" id="<?= View::e($sectionId) ?>" <?= $sectionActive ? '' : 'hidden' ?>>
            <?php foreach ($section['items'] as $navItem): $active = $isActiveRoute($navItem['matches']); ?>
              <a href="<?= View::e($base . $navItem['href']) ?>" class="nav-item <?= $active ? 'active' : '' ?>"><?= $icon($navItem['icon']) ?><span><?= View::e($navItem['label']) ?></span></a>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </nav>
    <?php
      $safetyTone = $safety['api'] === 'stopped' && $safety['automation'] === 'stopped'
          ? 'danger'
          : (($safety['api'] === 'stopped' || $safety['automation'] === 'stopped') ? 'warning' : 'success');
      $safetyHref = ($user['role'] ?? '') === 'admin' && !Auth::isTemporary() ? $base . '/stop/' : null;
    ?>
    <?php if ($safetyHref !== null): ?>
      <a class="sidebar-safety is-<?= View::e($safetyTone) ?>" href="<?= View::e($safetyHref) ?>" aria-label="Abrir freno de mano. <?= View::e((string) $safety['label']) ?>">
    <?php else: ?>
      <div class="sidebar-safety is-<?= View::e($safetyTone) ?>" role="status">
    <?php endif; ?>
        <span class="sidebar-safety-dot" aria-hidden="true"></span>
        <span>
          <strong><?= View::e((string) $safety['label']) ?></strong>
          <small><?= View::e($safety['automation'] === 'stopped' ? 'Automatización detenida' : 'Automatización disponible') ?></small>
        </span>
        <?php if ($safetyHref !== null): ?><span class="sidebar-safety-action">Abrir</span><?php endif; ?>
    <?= $safetyHref !== null ? '</a>' : '</div>' ?>
    <div class="sidebar-user">
      <div class="avatar"><?= View::e(mb_strtoupper(mb_substr($user['name'] ?? 'U', 0, 1))) ?></div>
      <div><strong><?= View::e($user['name'] ?? 'Usuario') ?></strong><small><?= View::e(ucfirst($user['role'] ?? '')) ?></small></div>
      <form action="<?= View::e($base) ?>/logout" method="post"><input type="hidden" name="_token" value="<?= Csrf::token() ?>"><button class="icon-button inverse" aria-label="Cerrar sesión"><?= $icon('logout') ?></button></form>
    </div>
    <div class="sidebar-version">ERP Meli v<?= View::e($version) ?></div>
  </aside>
  <div class="main-wrap">
    <header class="topbar">
      <button class="icon-button menu-toggle" id="menuToggle" aria-label="Abrir menú"><?= $icon('menu') ?></button>
      <div class="topbar-spacer"></div>
      <form class="context-form" method="get" action="" data-shell-snapshot-url="<?= View::e($base) ?>/shell/snapshot.json" data-selected-company="<?= $selectedCompany ?>" data-selected-account="<?= $selectedAccount ?>">
        <label class="top-select" for="global-company"><?= $icon('building') ?><span>Empresa</span><select id="global-company" name="company_id" data-context-select data-shell-companies><option value="<?= $selectedCompany ?>">Cargando empresas…</option></select></label>
        <label class="top-select account-select" for="global-account"><?= $icon('link') ?><span>Cuenta</span><select id="global-account" name="account_id" data-context-select data-shell-accounts><option value="<?= $selectedAccount ?>">Cargando cuentas…</option></select></label>
        <fieldset class="top-select date-select"><legend>Rango</legend><label class="sr-only" for="global-from">Desde</label><input id="global-from" type="date" name="from" value="<?= View::e($selectedFrom) ?>" data-context-select aria-label="Desde"><b aria-hidden="true">—</b><label class="sr-only" for="global-to">Hasta</label><input id="global-to" type="date" name="to" value="<?= View::e($selectedTo) ?>" data-context-select aria-label="Hasta"></fieldset>
      </form>
      <a class="icon-button notification" href="<?= View::e($base) ?>/notifications" aria-label="Notificaciones" data-shell-notification><?= $icon('bell') ?><span data-shell-unread hidden></span></a>
    </header>
    <main class="page-content">
      <?php if ($message = Session::flash('success')): ?><div class="alert success" role="status" aria-live="polite"><?= View::e($message) ?></div><?php endif; ?>
      <?php if ($message = Session::flash('warning')): ?><div class="alert warning" role="status" aria-live="polite"><?= View::e($message) ?></div><?php endif; ?>
      <?php if ($message = Session::flash('error')): ?><div class="alert danger" role="alert" aria-live="assertive"><?= View::e($message) ?></div><?php endif; ?>
      <?php if (Auth::isTemporary()): ?><div class="alert warning">Acceso temporal activo. Vence el: <?= View::e($user['expires_at'] ?? 'sin fecha') ?>.</div><?php endif; ?>
      <a class="api-global-alert" href="<?= View::e($base) ?>/settings/api-health" role="status" data-shell-api-alert hidden>
        <span class="api-global-alert-mark" aria-hidden="true">!</span>
        <span><strong data-shell-api-title></strong><small data-shell-api-message></small></span>
        <span class="api-global-alert-action">Revisar</span>
      </a>
      <?php if ($contextTabs !== []): ?>
      <nav class="context-tabs" aria-label="Secciones relacionadas">
        <?php foreach ($contextTabs as $tab): ?>
          <a href="<?= View::e($base . $tab['href']) ?>" class="<?= $navigation->isActive($routePath, $tab['matches']) ? 'active' : '' ?>"><?= View::e($tab['label']) ?></a>
        <?php endforeach; ?>
      </nav>
      <?php endif; ?>
      <?= $content ?>
    </main>
    <footer class="app-footer">ERP Meli v<?= View::e($version) ?> · ML_WRITE_ENABLED=<?= Env::bool('ML_WRITE_ENABLED', false) ? 'true' : 'false' ?></footer>
  </div>
</div>
<div class="navigation-progress" data-navigation-progress aria-hidden="true"></div>
<script src="<?= View::e(View::asset($base, 'app.js')) ?>&amp;v=<?= View::e($appJsFingerprint) ?>"></script>
<script src="<?= View::e(View::asset($base, 'catalog.js')) ?>&amp;v=<?= View::e($catalogJsFingerprint) ?>"></script>
<script src="<?= View::e(View::asset($base, 'ux.js')) ?>&amp;v=<?= View::e($uxJsFingerprint) ?>"></script>
<script src="<?= View::e(View::asset($base, 'performance.js')) ?>&amp;v=<?= View::e($performanceJsFingerprint) ?>"></script>
<?php foreach ($moduleAssets['js'] as $moduleJs): ?><script src="<?= View::e(View::asset($base, preg_replace('#^assets/#', '', $moduleJs) ?: $moduleJs)) ?>&amp;v=<?= View::e(AssetVersionService::fingerprint($moduleJs)) ?>"></script><?php endforeach; ?>
</body>
</html>
