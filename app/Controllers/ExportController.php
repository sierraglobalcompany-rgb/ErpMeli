<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\View;

final class ExportController
{
    public function index(): void
    {
        Auth::requireLogin();
        View::render('exports/index');
    }
}
