<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Core\Database;
use App\Services\ClaimSyncService;

final class ClaimController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = [
            'account_id' => (int) ($_GET['account_id'] ?? 0),
            'type' => trim((string) ($_GET['type'] ?? '')),
            'stage' => trim((string) ($_GET['stage'] ?? '')),
            'status' => trim((string) ($_GET['status'] ?? '')),
            'reason_id' => trim((string) ($_GET['reason_id'] ?? '')),
            'from' => trim((string) ($_GET['from'] ?? '')),
            'to' => trim((string) ($_GET['to'] ?? '')),
        ];
        $claims = $filters['account_id'] > 0 ? (new ClaimSyncService($filters['account_id']))->listLocal($filters) : $this->allClaims($filters);
        $options = $this->options();
        View::render('sales/claims/index', compact('claims', 'filters', 'options'));
    }

    public function sync(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        if (Auth::isTemporary()) {
            Session::flash('error', 'Los usuarios temporales no pueden sincronizar reclamos.');
            $this->redirect('/claims');
        }
        Session::flash(
            'info',
            'La sincronización general de reclamos está retirada porque no tiene consumidor vigente. No se creó trabajo ni se consultó Mercado Libre.'
        );
        $this->redirect('/claims?account_id=' . max(0, (int) ($_POST['account_id'] ?? 0)));
    }

    private function allClaims(array $filters): array
    {
        return (new ClaimSyncService(0))->listLocal($filters);
    }

    private function options(): array
    {
        $pdo = Database::connection();
        $accountIds = (new AuthorizedBusinessScope())->accountIds();
        if ($accountIds === []) {
            return ['accounts' => [], 'types' => [], 'stages' => [], 'statuses' => [], 'reasons' => []];
        }
        $placeholders = implode(',', array_fill(0, count($accountIds), '?'));
        $accounts = $pdo->prepare('SELECT id,account_name FROM meli_accounts WHERE id IN (' . $placeholders . ') ORDER BY account_name');
        $accounts->execute($accountIds);

        $distinct = static function (string $select, string $present, string $order) use ($pdo, $placeholders, $accountIds): array {
            $stmt = $pdo->prepare(
                'SELECT DISTINCT ' . $select . ' FROM meli_claims
                 WHERE meli_account_id IN (' . $placeholders . ') AND ' . $present . '
                 ORDER BY ' . $order
            );
            $stmt->execute($accountIds);
            return $stmt->fetchAll($select === 'reason_id, reason_name' ? \PDO::FETCH_ASSOC : \PDO::FETCH_COLUMN);
        };
        return [
            'accounts' => $accounts->fetchAll(\PDO::FETCH_ASSOC),
            'types' => $distinct('type', 'type IS NOT NULL AND type<>""', 'type'),
            'stages' => $distinct('stage', 'stage IS NOT NULL AND stage<>""', 'stage'),
            'statuses' => $distinct('status', 'status IS NOT NULL AND status<>""', 'status'),
            'reasons' => $distinct('reason_id, reason_name', 'reason_id IS NOT NULL AND reason_id<>""', 'reason_name,reason_id'),
        ];
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
