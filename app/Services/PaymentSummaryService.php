<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;
use Throwable;

final class PaymentSummaryService
{
    private SchemaInspectorService $schema;
    private AuthorizedBusinessScope $scope;
    /** @var array<string, array<string, mixed>>|null */
    private ?array $paymentColumns = null;

    /** @var list<string> */
    private const REQUIRED_COLUMNS = [
        'meli_account_id',
        'external_payment_id',
        'status',
        'payment_method_id',
        'payment_type',
        'transaction_amount',
        'marketplace_fee',
        'date_approved',
        'synced_at',
    ];

    /** @var list<string> */
    private const DETAIL_COLUMNS = [
        'detail_status',
        'detail_attempts',
        'detail_last_attempt_at',
        'detail_unavailable_at',
        'detail_error_code',
        'detail_error_message',
    ];

    public function __construct(?SchemaInspectorService $schema = null, ?AuthorizedBusinessScope $scope = null)
    {
        $this->schema = $schema ?? new SchemaInspectorService();
        $this->scope = $scope ?? new AuthorizedBusinessScope();
    }

    public function list(array $filters = []): array
    {
        if (!$this->hasPaymentsTable()) {
            return [];
        }

        [$where, $params] = $this->where($filters);
        [$order, $dir] = $this->order($filters);
        $perPage = in_array((int) ($filters['per_page'] ?? 50), [25, 50, 100], true) ? (int) $filters['per_page'] : 50;
        $page = max(1, (int) ($filters['page'] ?? 1));
        $stmt = Database::connection()->prepare(
            'SELECT ' . implode(', ', $this->selectColumns()) . '
             FROM meli_payments p
             ' . $this->accountJoin() . '
             ' . $this->orderJoin() . '
             WHERE ' . implode(' AND ', $where) . "
             ORDER BY {$order} {$dir} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage)
        );
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function totals(array $filters = []): array
    {
        if (!$this->hasPaymentsTable()) {
            return $this->emptyTotals();
        }

        [$where, $params] = $this->where($filters);
        $amount = $this->hasColumn('transaction_amount') ? 'p.transaction_amount' : '0';
        $fees = $this->hasColumn('marketplace_fee') ? 'p.marketplace_fee' : '0';
        $notApproved = $this->hasColumn('status')
            ? "CASE WHEN p.status <> 'approved' OR p.status IS NULL THEN 1 ELSE 0 END"
            : '0';

        $stmt = Database::connection()->prepare(
            'SELECT COUNT(*) count_rows,
                    COALESCE(SUM(' . $amount . '),0) amount,
                    COALESCE(SUM(' . $fees . '),0) fees,
                    COALESCE(SUM(' . $notApproved . '),0) not_approved
             FROM meli_payments p
             ' . $this->orderJoin() . '
             WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: $this->emptyTotals();
    }

    public function options(): array
    {
        return [
            'accounts' => $this->accountOptions(),
            'statuses' => $this->distinctOptions('status'),
            'methods' => $this->distinctOptions('payment_method_id'),
            'types' => $this->distinctOptions('payment_type'),
            'detail_statuses' => $this->hasColumn('detail_status') ? ['summary', 'expanded', 'unavailable', 'error'] : ['summary'],
        ];
    }

    public function diagnostics(?Throwable $lastError = null): array
    {
        $tableExists = $this->hasPaymentsTable();
        $missingRequired = $tableExists ? $this->schema->missingColumns('meli_payments', self::REQUIRED_COLUMNS) : self::REQUIRED_COLUMNS;
        $missingDetail = $tableExists ? $this->schema->missingColumns('meli_payments', self::DETAIL_COLUMNS) : self::DETAIL_COLUMNS;
        $settings = new AppSettingsService();

        return [
            'table_exists' => $tableExists,
            'missing_required' => $missingRequired,
            'missing_detail' => $missingDetail,
            'detail_filter_available' => $this->hasColumn('detail_status'),
            'source' => $settings->get('payments.source', 'orders_summary'),
            'expand_details_enabled' => $settings->bool('payments.expand_details_enabled', false),
            'latest_payment_migration' => $this->latestPaymentMigration(),
            'last_error' => $lastError
                ? UiLabelPresenter::safeOperationMessage($lastError->getMessage())
                : null,
        ];
    }

    private function where(array $filters): array
    {
        $where = [];
        $params = [];
        if ($this->hasColumn('meli_account_id')) {
            [$scopeSql, $scopeParams] = $this->accountScope('p.meli_account_id', (int) ($filters['account_id'] ?? 0));
            $where[] = $scopeSql;
            $params += $scopeParams;
        } else {
            // Una tabla legacy sin identidad de cuenta no puede exponerse en
            // una pantalla multiempresa.
            $where[] = '1=0';
        }

        foreach (['status' => 'p.status', 'payment_method_id' => 'p.payment_method_id', 'payment_type' => 'p.payment_type'] as $key => $column) {
            if (($filters[$key] ?? '') !== '' && $this->hasColumn($key)) {
                $where[] = $column . '=:' . $key;
                $params[$key] = (string) $filters[$key];
            }
        }

        if (($filters['detail_status'] ?? '') !== '') {
            if ($this->hasColumn('detail_status')) {
                $where[] = 'p.detail_status=:detail_status';
                $params['detail_status'] = (string) $filters['detail_status'];
            } elseif ((string) $filters['detail_status'] !== 'summary') {
                $where[] = '1=0';
            }
        }

        $dateColumn = ($filters['date_field'] ?? 'approved') === 'synced' ? 'synced_at' : 'date_approved';
        if (!$this->hasColumn($dateColumn)) {
            $dateColumn = $this->hasColumn('date_approved') ? 'date_approved' : ($this->hasColumn('synced_at') ? 'synced_at' : '');
        }
        if ($dateColumn !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['from'] ?? ''))) {
            $where[] = 'p.' . $dateColumn . '>=:from';
            $params['from'] = $filters['from'] . ' 00:00:00';
        }
        if ($dateColumn !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($filters['to'] ?? ''))) {
            $where[] = 'p.' . $dateColumn . '<=:to';
            $params['to'] = $filters['to'] . ' 23:59:59';
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $search = [];
            if ($this->hasColumn('external_payment_id')) {
                $search[] = 'p.external_payment_id=:q';
            }
            if ($this->canJoinOrders()) {
                $search[] = 'o.external_order_id=:q';
            }
            if ($search) {
                $where[] = '(' . implode(' OR ', $search) . ')';
                $params['q'] = $q;
            }
        }
        return [$where, $params];
    }

    private function order(array $filters): array
    {
        $map = [
            'date' => $this->dateOrderExpression(),
            'status' => $this->hasColumn('status') ? 'p.status' : $this->fallbackOrderExpression(),
            'method' => $this->hasColumn('payment_method_id') ? 'p.payment_method_id' : $this->fallbackOrderExpression(),
            'amount' => $this->hasColumn('transaction_amount') ? 'p.transaction_amount' : $this->fallbackOrderExpression(),
            'fee' => $this->hasColumn('marketplace_fee') ? 'p.marketplace_fee' : $this->fallbackOrderExpression(),
        ];
        $sort = (string) ($filters['sort'] ?? 'date');
        $dir = strtoupper((string) ($filters['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';
        return [$map[$sort] ?? $map['date'], $dir];
    }

    /**
     * @return list<string>
     */
    private function selectColumns(): array
    {
        $select = [];
        foreach ([
            'id' => 'NULL',
            'meli_account_id' => 'NULL',
            'external_payment_id' => "''",
            'status' => 'NULL',
            'status_detail' => 'NULL',
            'payment_method_id' => 'NULL',
            'payment_type' => 'NULL',
            'transaction_amount' => '0',
            'marketplace_fee' => '0',
            'date_approved' => 'NULL',
            'synced_at' => 'NULL',
            'detail_status' => "'summary'",
        ] as $column => $fallback) {
            $select[] = $this->hasColumn($column) ? 'p.' . $column : $fallback . ' AS ' . $column;
        }
        $select[] = $this->canJoinAccounts() ? 'a.account_name' : "'Cuenta sin tabla' AS account_name";
        $select[] = $this->canJoinOrders() ? 'o.external_order_id' : 'NULL AS external_order_id';
        return $select;
    }

    private function accountJoin(): string
    {
        return $this->canJoinAccounts() ? 'JOIN meli_accounts a ON a.id=p.meli_account_id' : '';
    }

    private function orderJoin(): string
    {
        return $this->canJoinOrders() ? 'LEFT JOIN meli_orders o ON o.id=p.meli_order_id' : '';
    }

    private function canJoinAccounts(): bool
    {
        return $this->schema->hasTable('meli_accounts') && $this->hasColumn('meli_account_id');
    }

    private function canJoinOrders(): bool
    {
        return $this->schema->hasTable('meli_orders') && $this->hasColumn('meli_order_id');
    }

    private function hasPaymentsTable(): bool
    {
        return $this->schema->hasTable('meli_payments');
    }

    private function hasColumn(string $column): bool
    {
        if (!$this->hasPaymentsTable()) {
            return false;
        }
        if ($this->paymentColumns === null) {
            $this->paymentColumns = $this->schema->columns('meli_payments');
        }
        return array_key_exists($column, $this->paymentColumns);
    }

    private function distinctOptions(string $column): array
    {
        if (!$this->hasPaymentsTable() || !$this->hasColumn($column)) {
            return [];
        }
        try {
            [$scopeSql, $params] = $this->accountScope('meli_account_id', 0);
            $stmt = Database::connection()->prepare(
                'SELECT DISTINCT ' . $column . '
                 FROM meli_payments
                 WHERE ' . $scopeSql . ' AND ' . $column . ' IS NOT NULL AND ' . $column . '<>""
                 ORDER BY ' . $column
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable) {
            return [];
        }
    }

    private function accountOptions(): array
    {
        if (!$this->schema->hasTable('meli_accounts')) {
            return [];
        }
        try {
            $accountIds = $this->scope->accountIds();
            if ($accountIds === []) {
                return [];
            }
            $names = [];
            $params = [];
            foreach ($accountIds as $index => $accountId) {
                $name = 'scope_account_' . $index;
                $names[] = ':' . $name;
                $params[$name] = $accountId;
            }
            $stmt = Database::connection()->prepare(
                'SELECT id,account_name FROM meli_accounts WHERE id IN (' . implode(',', $names) . ') ORDER BY account_name'
            );
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return [];
        }
    }

    private function latestPaymentMigration(): ?string
    {
        if (!$this->schema->hasTable('schema_migrations')) {
            return null;
        }
        try {
            $stmt = Database::connection()->query("SELECT version FROM schema_migrations WHERE version LIKE '%payment%' OR version LIKE '004_%' OR version LIKE '024_%' ORDER BY applied_at DESC LIMIT 1");
            $value = $stmt->fetchColumn();
            return $value ? (string) $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function dateOrderExpression(): string
    {
        if ($this->hasColumn('date_approved') && $this->hasColumn('synced_at')) {
            return 'COALESCE(p.date_approved,p.synced_at)';
        }
        if ($this->hasColumn('date_approved')) {
            return 'p.date_approved';
        }
        if ($this->hasColumn('synced_at')) {
            return 'p.synced_at';
        }
        return $this->hasColumn('id') ? 'p.id' : '1';
    }

    private function fallbackOrderExpression(): string
    {
        return $this->hasColumn('id') ? 'p.id' : '1';
    }

    /** @return array{0:string,1:array<string,int>} */
    private function accountScope(string $column, int $requestedAccountId): array
    {
        $accountIds = $this->scope->accountIds();
        if ($requestedAccountId > 0) {
            if (!in_array($requestedAccountId, $accountIds, true)) {
                throw new \App\Core\HttpException(404, 'No se encontró la cuenta solicitada.');
            }
            return [$column . '=:scope_account', ['scope_account' => $requestedAccountId]];
        }
        if ($accountIds === []) {
            return ['1=0', []];
        }
        $names = [];
        $params = [];
        foreach ($accountIds as $index => $accountId) {
            $name = 'scope_account_' . $index;
            $names[] = ':' . $name;
            $params[$name] = $accountId;
        }
        return [$column . ' IN (' . implode(',', $names) . ')', $params];
    }

    private function emptyTotals(): array
    {
        return ['count_rows' => 0, 'amount' => 0, 'fees' => 0, 'not_approved' => 0];
    }
}
