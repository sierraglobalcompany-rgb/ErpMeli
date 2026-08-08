<?php
declare(strict_types=1);
namespace App\Modules\MeliLogistics\Controllers;
final class DashboardController extends \App\Modules\Shared\Controllers\ModuleDashboardController
{
    protected function moduleId(): string { return 'meli-logistics'; }
}
