<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Env;
use App\Core\Session;
use App\Core\View;
use App\Services\AuditService;
use App\Services\CompanyOptionService;
use App\Services\Logger;
use PDO;
use RuntimeException;
use Throwable;

final class CompanyController
{
    public function index(): void
    {
        Auth::requireRole('admin');
        try {
            View::render('companies/index', [
                'companies' => $this->companyList(),
                'editing' => null,
                'detail' => null,
                'duplicateCompanies' => (new CompanyOptionService())->duplicateActiveNames(),
            ]);
        } catch (Throwable $e) {
            Logger::write('error', 'Error cargando empresas.', ['error' => $e->getMessage()]);
            Session::flash('error', 'No se pudo cargar Empresas. Revise logs técnicos.');
            View::render('companies/index', ['companies' => [], 'editing' => null, 'detail' => null, 'duplicateCompanies' => []]);
        }
    }

    public function show(): void
    {
        Auth::requireRole('admin');
        $id = (int) ($_GET['id'] ?? 0);
        try {
            $company = $this->find($id);
            $pdo = Database::connection();
            $accounts = $pdo->prepare('SELECT id,account_name,status,last_sync_at FROM meli_accounts WHERE company_id=:id ORDER BY account_name');
            $accounts->execute(['id' => $id]);
            $reports = $pdo->prepare('SELECT id,report_month,status,estimated_net FROM monthly_reports WHERE issuer_company_id=:issuer_id OR customer_company_id=:customer_id ORDER BY report_month DESC LIMIT 20');
            $reports->execute(['issuer_id' => $id, 'customer_id' => $id]);
            $dateReportRows = [];
            if ($this->hasDateBillingColumns()) {
                $dateReports = $pdo->prepare('SELECT id,date_from,date_to,status,estimated_net,manual_base FROM date_report_runs WHERE issuer_company_id=:issuer_id OR customer_company_id=:customer_id ORDER BY date_to DESC LIMIT 20');
                $dateReports->execute(['issuer_id' => $id, 'customer_id' => $id]);
                $dateReportRows = $dateReports->fetchAll(PDO::FETCH_ASSOC);
            }
            $detail = [
                'company' => $company,
                'accounts' => $accounts->fetchAll(PDO::FETCH_ASSOC),
                'reports' => $reports->fetchAll(PDO::FETCH_ASSOC),
                'date_reports' => $dateReportRows,
                'usage' => $this->usage($id),
            ];
            View::render('companies/index', [
                'companies' => $this->companyList(),
                'editing' => $company,
                'detail' => $detail,
                'duplicateCompanies' => (new CompanyOptionService())->duplicateActiveNames(),
            ]);
        } catch (Throwable $e) {
            Logger::write('error', 'Error abriendo detalle de empresa.', ['company_id' => $id, 'error' => $e->getMessage()]);
            Session::flash('error', 'No se pudo abrir la empresa. Revise logs técnicos.');
            $this->redirect('/companies');
        }
    }

    public function store(): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        try {
            $data = $this->validated();
            $stmt = Database::connection()->prepare('INSERT INTO companies (name,nit,legal_name,email,phone,address,city,department,person_type,status) VALUES (:name,:nit,:legal_name,:email,:phone,:address,:city,:department,:person_type,1)');
            $stmt->execute($data);
            $id = (int) Database::connection()->lastInsertId();
            AuditService::record('create', 'companies', 'company', $id, null, null, $data);
            Session::flash('success', 'Empresa creada.');
        } catch (Throwable $e) {
            Logger::write('error', 'Error creando empresa.', ['error' => $e->getMessage()]);
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/companies');
    }

    public function update(): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $before = $this->find($id);
            $data = $this->validated();
            $data['id'] = $id;
            $stmt = Database::connection()->prepare('UPDATE companies SET name=:name,nit=:nit,legal_name=:legal_name,email=:email,phone=:phone,address=:address,city=:city,department=:department,person_type=:person_type WHERE id=:id AND deleted_at IS NULL');
            $stmt->execute($data);
            AuditService::record('update', 'companies', 'company', $id, null, $before, $data);
            Session::flash('success', 'Empresa actualizada.');
        } catch (Throwable $e) {
            Logger::write('error', 'Error actualizando empresa.', ['company_id' => $id, 'error' => $e->getMessage()]);
            Session::flash('error', \App\Services\SafeErrorPresenter::message($e));
        }
        $this->redirect('/companies');
    }

    public function deactivate(): void
    {
        $this->changeStatus(0, 'deactivate', 'Empresa desactivada.');
    }

    public function reactivate(): void
    {
        $this->changeStatus(1, 'reactivate', 'Empresa reactivada.');
    }

    public function delete(): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $before = $this->find($id);
            $usage = $this->usage($id);
            if (array_sum($usage) > 0) {
                Database::connection()->prepare('UPDATE companies SET status=0,deactivated_at=COALESCE(deactivated_at,NOW()) WHERE id=:id')->execute(['id' => $id]);
                AuditService::record('delete_blocked_soft_deactivate', 'companies', 'company', $id, null, $before, ['usage' => $usage]);
                Session::flash('success', 'La empresa tiene datos asociados; se desactivó en lugar de eliminarse.');
            } else {
                Database::connection()->prepare('UPDATE companies SET deleted_at=NOW(),status=0 WHERE id=:id')->execute(['id' => $id]);
                AuditService::record('delete', 'companies', 'company', $id, null, $before, ['deleted_at' => date('c')]);
                Session::flash('success', 'Empresa eliminada.');
            }
        } catch (Throwable $e) {
            Logger::write('error', 'Error eliminando empresa.', ['company_id' => $id, 'error' => $e->getMessage()]);
            Session::flash('error', 'No se pudo eliminar la empresa. Revise logs técnicos.');
        }
        $this->redirect('/companies');
    }

    private function changeStatus(int $status, string $action, string $message): void
    {
        Auth::requireRole('admin');
        Csrf::validate($_POST['_token'] ?? null);
        $id = (int) ($_POST['id'] ?? 0);
        try {
            $before = $this->find($id);
            $sql = $status === 1 ? 'UPDATE companies SET status=1,deactivated_at=NULL WHERE id=:id' : 'UPDATE companies SET status=0,deactivated_at=NOW() WHERE id=:id';
            Database::connection()->prepare($sql)->execute(['id' => $id]);
            AuditService::record($action, 'companies', 'company', $id, null, $before, ['status' => $status]);
            Session::flash('success', $message);
        } catch (Throwable $e) {
            Logger::write('error', 'Error cambiando estado de empresa.', ['company_id' => $id, 'status' => $status, 'error' => $e->getMessage()]);
            Session::flash('error', 'No se pudo actualizar la empresa. Revise logs técnicos.');
        }
        $this->redirect('/companies');
    }

    private function validated(): array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        $legal = trim((string) ($_POST['legal_name'] ?? ''));
        $email = trim((string) ($_POST['email'] ?? ''));
        $nit = trim((string) ($_POST['nit'] ?? ''));
        $personType = (string) ($_POST['person_type'] ?? 'juridica');
        if ($name === '') {
            throw new RuntimeException('El nombre comercial es obligatorio.');
        }
        if ($legal === '') {
            throw new RuntimeException('La razón social es obligatoria.');
        }
        if ($nit !== '' && !preg_match('/^[0-9A-Za-z.\-]{5,40}$/', $nit)) {
            throw new RuntimeException('El NIT no tiene un formato válido.');
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo no es válido.');
        }
        if (!in_array($personType, ['natural', 'juridica'], true)) {
            $personType = 'juridica';
        }
        return [
            'name' => $name,
            'nit' => $nit !== '' ? $nit : null,
            'legal_name' => $legal,
            'email' => $email !== '' ? $email : null,
            'phone' => trim((string) ($_POST['phone'] ?? '')) ?: null,
            'address' => trim((string) ($_POST['address'] ?? '')) ?: null,
            'city' => trim((string) ($_POST['city'] ?? '')) ?: null,
            'department' => trim((string) ($_POST['department'] ?? '')) ?: null,
            'person_type' => $personType,
        ];
    }

    private function companyList(): array
    {
        $dateReportsCount = $this->hasDateBillingColumns()
            ? " + (SELECT COUNT(*) FROM date_report_runs d WHERE d.issuer_company_id=c.id OR d.customer_company_id=c.id)"
            : '';
        return Database::connection()->query(
            "SELECT c.*,
                    (SELECT COUNT(*) FROM meli_accounts a WHERE a.company_id=c.id) accounts_count,
                    (SELECT COUNT(*) FROM monthly_reports r WHERE r.issuer_company_id=c.id OR r.customer_company_id=c.id){$dateReportsCount} reports_count
             FROM companies c
             WHERE c.deleted_at IS NULL
             ORDER BY c.status DESC,c.name"
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    private function find(int $id): array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM companies WHERE id=:id AND deleted_at IS NULL');
        $stmt->execute(['id' => $id]);
        $company = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$company) {
            throw new RuntimeException('Empresa no encontrada.');
        }
        return $company;
    }

    private function usage(int $id): array
    {
        $pdo = Database::connection();
        $queries = [
            'accounts' => ['SELECT COUNT(*) FROM meli_accounts WHERE company_id=:company_id', ['company_id' => $id]],
            'orders' => ['SELECT COUNT(*) FROM meli_orders o JOIN meli_accounts a ON a.id=o.meli_account_id WHERE a.company_id=:company_id', ['company_id' => $id]],
            'monthly_reports' => ['SELECT COUNT(*) FROM monthly_reports WHERE issuer_company_id=:issuer_id OR customer_company_id=:customer_id', ['issuer_id' => $id, 'customer_id' => $id]],
            'settings' => ['SELECT COUNT(*) FROM company_settings WHERE company_id=:company_id', ['company_id' => $id]],
        ];
        if ($this->hasDateBillingColumns()) {
            $queries['date_billings'] = ['SELECT COUNT(*) FROM date_report_runs WHERE issuer_company_id=:issuer_id OR customer_company_id=:customer_id', ['issuer_id' => $id, 'customer_id' => $id]];
        }
        $counts = [];
        foreach ($queries as $key => [$sql, $params]) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $counts[$key] = (int) $stmt->fetchColumn();
        }
        return $counts;
    }

    private function redirect(string $path): never
    {
        header('Location: ' . rtrim(Env::get('APP_URL', ''), '/') . $path);
        exit;
    }

    private function hasDateBillingColumns(): bool
    {
        static $hasColumns = null;
        if ($hasColumns !== null) {
            return $hasColumns;
        }
        try {
            $stmt = Database::connection()->query("SHOW COLUMNS FROM date_report_runs LIKE 'issuer_company_id'");
            $hasColumns = (bool) $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            $hasColumns = false;
        }
        return $hasColumns;
    }
}
