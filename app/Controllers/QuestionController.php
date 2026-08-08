<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\QuestionSyncService;
use App\Services\BusinessScopeContext;
use PDO;

final class QuestionController
{
    public function index(): void
    {
        Auth::requireLogin();
        $filters = [
            'company_id' => (int) ($_GET['company_id'] ?? 0),
            'account_id' => (int) ($_GET['account_id'] ?? 0),
        ];
        $questions = (new QuestionSyncService())->pending($filters);
        $scope = (new BusinessScopeContext())->accountPredicate('a.id', null, $filters['company_id']);
        $accountsQuery = Database::connection()->prepare(
            'SELECT a.id,a.company_id,a.account_name
             FROM meli_accounts a
             JOIN companies c ON c.id=a.company_id AND c.status=1
             WHERE ' . $scope['sql'] . '
             ORDER BY a.account_name'
        );
        $accountsQuery->execute($scope['params']);
        $accounts = $accountsQuery->fetchAll(PDO::FETCH_ASSOC);
        View::render('sales/questions/index', compact('questions', 'accounts', 'filters'));
    }

    public function sync(): void
    {
        Auth::requireRole('admin', 'operador');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $accountId = (int) ($_POST['account_id'] ?? 0);
            Session::flash('info', 'La revisión de preguntas se preparará como trabajo CLI. Esta página no consultó Mercado Libre.');
            $this->redirect('/settings/manual-processing?scope=sales&account_id=' . max(0, $accountId) . '&origin=questions');
        } catch (\Throwable $e) {
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e, 'No fue posible preparar la revisión de preguntas.'));
        }
        $this->redirect('/questions');
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }
}
