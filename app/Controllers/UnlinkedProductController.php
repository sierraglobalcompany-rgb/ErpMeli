<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\ProductMatchSuggestionService;
use App\Services\UnlinkedProductService;

final class UnlinkedProductController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = [
            'account_id' => (int) ($_GET['account_id'] ?? 0),
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page' => in_array((int) ($_GET['per_page'] ?? 50), [25, 50, 100], true) ? (int) $_GET['per_page'] : 50,
        ];
        $pageData = (new UnlinkedProductService())->paginate($filters, $filters['page'], $filters['per_page']);
        $rows = $pageData['items'];
        $suggestions = (new ProductMatchSuggestionService())->pending();
        View::render('products/unlinked/index', compact('rows', 'pageData', 'filters', 'suggestions'));
    }

    public function suggest(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $count = (new ProductMatchSuggestionService())->generateForUnlinked();
            Session::flash('success', "Sugerencias generadas: {$count}.");
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/products/unlinked');
    }

    public function ignore(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            Database::connection()->prepare('INSERT IGNORE INTO product_unlinked_ignores (meli_account_id,external_item_id,external_variation_id,reason,ignored_by) VALUES (:account,:item,:variation,:reason,:user)')
                ->execute([
                    'account' => (int) ($_POST['meli_account_id'] ?? 0),
                    'item' => (string) ($_POST['external_item_id'] ?? ''),
                    'variation' => ($_POST['external_variation_id'] ?? '') !== '' ? (int) $_POST['external_variation_id'] : null,
                    'reason' => trim((string) ($_POST['reason'] ?? 'Ignorado manualmente')) ?: 'Ignorado manualmente',
                    'user' => Auth::id(),
                ]);
            Session::flash('success', 'Producto omitido de la lista sin vincular.');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/products/unlinked');
    }

    public function ignoreBatch(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        $selected = is_array($_POST['selected'] ?? null) ? array_slice($_POST['selected'], 0, 100) : [];
        $count = 0;
        $stmt = Database::connection()->prepare(
            'INSERT IGNORE INTO product_unlinked_ignores
             (meli_account_id,external_item_id,external_variation_id,reason,ignored_by)
             VALUES (?,?,?,?,?)'
        );
        foreach ($selected as $value) {
            $parts = explode('|', (string) $value, 3);
            if (count($parts) !== 3 || (int) $parts[0] <= 0 || !preg_match('/^[A-Za-z0-9_-]{1,80}$/', $parts[1])) {
                continue;
            }
            $stmt->execute([(int) $parts[0], $parts[1], $parts[2] !== '' ? (int) $parts[2] : null, 'Ignorado por selección múltiple', Auth::id()]);
            $count += $stmt->rowCount() > 0 ? 1 : 0;
        }
        Session::flash('success', $count . ' productos fueron omitidos de la lista.');
        $this->redirect('/products/unlinked');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
