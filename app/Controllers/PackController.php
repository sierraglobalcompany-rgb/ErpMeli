<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;
use App\Services\PackQueryService;

final class PackController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = [
            'account_id' => (int) ($_GET['account_id'] ?? 0),
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page' => in_array((int) ($_GET['per_page'] ?? 50), [25, 50, 100], true) ? (int) $_GET['per_page'] : 50,
        ];
        $packs = (new PackQueryService())->list($filters);
        View::render('sales/packs/index', compact('packs', 'filters'));
    }
}
