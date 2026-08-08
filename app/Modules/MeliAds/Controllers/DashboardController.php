<?php
declare(strict_types=1);
namespace App\Modules\MeliAds\Controllers;
final class DashboardController extends \App\Modules\Shared\Controllers\ModuleDashboardController
{
    protected function moduleId(): string { return 'meli-ads'; }
}
