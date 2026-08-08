<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class CronV3WorkTypeRegistry
{
    /** @var array<string,string> */
    private array $types = [
        'notification_spool' => 'local',
        'notification_normalize' => 'local',
        'notification_backfill' => 'local',
        'notification_identity_repair' => 'local',
        'recurring_schedule' => 'local',
        'financial_local_projection' => 'local',
        'financial_recalc' => 'local',
        'financial_gap_scan' => 'local',
        'order_date_repair' => 'local',
        'operational_maintenance' => 'local',
        'monthly_report_maintenance' => 'local',
        'cron_v3_health_snapshot' => 'local',
        'oauth_refresh' => 'remote',
        'orders_search_page' => 'remote',
        'order_exact' => 'remote',
        'pack_exact' => 'remote',
        'shipment_exact' => 'remote',
        'sale_pack_reconciliation_exact' => 'remote',
        'sales_audit_page' => 'remote',
        'sales_repair_exact' => 'remote',
        'questions_search_page' => 'remote',
        'question_exact' => 'remote',
        'claims_search_page' => 'remote',
        'claim_exact' => 'remote',
        'sale_billing_capture' => 'remote',
        'sales_fiscal_exact' => 'remote',
        'items_search_page' => 'remote',
        'item_exact' => 'remote',
        'catalog_description_exact' => 'remote',
        'module_logistics_exact' => 'remote',
    ];

    public function register(string $workType, string $lane): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,79}$/', $workType) !== 1) {
            throw new InvalidArgumentException('Invalid Cron V3 work_type.');
        }
        if (!in_array($lane, ['local', 'remote'], true)) {
            throw new InvalidArgumentException('Invalid Cron V3 lane.');
        }
        if (isset($this->types[$workType]) && $this->types[$workType] !== $lane) {
            throw new InvalidArgumentException('Cron V3 work_type lane cannot change.');
        }
        $this->types[$workType] = $lane;
    }

    public function laneFor(string $workType): string
    {
        if (!isset($this->types[$workType])) {
            throw new InvalidArgumentException('Cron V3 work_type is not registered.');
        }
        return $this->types[$workType];
    }

    public function admits(string $workType, string $lane): bool
    {
        return ($this->types[$workType] ?? null) === $lane;
    }

    public function familyFor(string $workType): string
    {
        $this->laneFor($workType);
        return match (true) {
            str_starts_with($workType, 'notification_') => 'notifications',
            str_starts_with($workType, 'financial_'), $workType === 'sale_billing_capture' => 'finance',
            str_starts_with($workType, 'order_'), str_starts_with($workType, 'orders_'),
            str_starts_with($workType, 'sale_pack_'),
            str_starts_with($workType, 'pack_'), str_starts_with($workType, 'shipment_'),
            str_starts_with($workType, 'sales_') => 'sales',
            str_starts_with($workType, 'question'), str_starts_with($workType, 'claim') => 'attention',
            str_starts_with($workType, 'item'), str_starts_with($workType, 'catalog_') => 'catalog',
            str_starts_with($workType, 'module_') => 'modules',
            str_starts_with($workType, 'oauth_') => 'oauth',
            str_starts_with($workType, 'cron_v3_') => 'infrastructure',
            default => $workType,
        };
    }

    /** @return list<string> */
    public function forLane(string $lane): array
    {
        return array_keys(array_filter($this->types, static fn (string $value): bool => $value === $lane));
    }
}
