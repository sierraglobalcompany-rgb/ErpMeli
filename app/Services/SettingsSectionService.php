<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\SettingsDefinitionRepository;
use InvalidArgumentException;
use Throwable;

final class SettingsSectionService
{
    public function __construct(
        private readonly SettingsDefinitionRepository $definitions = new SettingsDefinitionRepository(),
        private readonly AppSettingsService $settings = new AppSettingsService()
    ) {
    }

    public function values(string $sectionKey): array
    {
        $section = $this->requireSection($sectionKey);
        $values = [];
        foreach ($section['fields'] as $field) {
            $default = $this->stringValue($field['recommended']);
            $values[$field['key']] = $this->settings->get($field['key'], $default);
        }
        return $values;
    }

    public function save(string $sectionKey, array $submitted, bool $restoreRecommended = false): array
    {
        $this->assertRetiredQuestionAdmission($sectionKey, $submitted);
        $section = $this->requireSection($sectionKey);
        $before = $this->values($sectionKey);
        $after = $before;
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            foreach ($section['fields'] as $field) {
                $key = $field['key'];
                $raw = $restoreRecommended ? $field['recommended'] : ($submitted[$key] ?? null);
                $after[$key] = $this->validate($field, $raw);
            }
            if ($sectionKey === 'mercadolibre') {
                $this->normalizeBilling429Backoff($after);
            }
            foreach ($section['fields'] as $field) {
                $key = $field['key'];
                $this->settings->set($key, $after[$key], $sectionKey);
            }
            AuditService::record(
                $restoreRecommended ? 'restore_recommended_settings' : 'update_settings_section',
                'settings',
                'settings_section',
                null,
                null,
                $before,
                $after
            );
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
        return $after;
    }

    private function assertRetiredQuestionAdmission(string $sectionKey, array $submitted): void
    {
        if ($sectionKey !== 'communications') {
            return;
        }
        foreach (['questions.sync_enabled', 'questions.endpoint_confirmed'] as $key) {
            if (in_array(strtolower(trim((string) ($submitted[$key] ?? '0'))), ['1', 'true', 'on', 'yes'], true)) {
                throw new \RuntimeException(
                    'La sincronización general de preguntas está retirada. No se cambió la configuración.'
                );
            }
        }
    }

    private function validate(array $field, mixed $raw): string
    {
        return match ($field['type']) {
            'boolean' => $this->booleanValue($raw),
            'number' => $this->numberValue($field, $raw),
            'select' => $this->selectValue($field, $raw),
            'text' => $this->textValue($field, $raw),
            default => throw new InvalidArgumentException('Tipo de configuración no soportado.'),
        };
    }

    private function booleanValue(mixed $raw): string
    {
        return in_array((string) $raw, ['1', 'true', 'on', 'yes'], true) ? '1' : '0';
    }

    private function numberValue(array $field, mixed $raw): string
    {
        if (!is_numeric($raw)) {
            throw new InvalidArgumentException('El valor de “' . $field['label'] . '” debe ser numérico.');
        }
        $value = (int) $raw;
        if ($value < (int) $field['min'] || $value > (int) $field['max']) {
            throw new InvalidArgumentException(
                '“' . $field['label'] . '” debe estar entre ' . $field['min'] . ' y ' . $field['max'] . '.'
            );
        }
        return (string) $value;
    }

    private function selectValue(array $field, mixed $raw): string
    {
        $value = (string) $raw;
        if (!array_key_exists($value, $field['options'])) {
            throw new InvalidArgumentException('La opción elegida para “' . $field['label'] . '” no es válida.');
        }
        return $value;
    }

    private function textValue(array $field, mixed $raw): string
    {
        $value = trim((string) $raw);
        if (($field['inputType'] ?? '') === 'email' && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('El correo indicado no es válido.');
        }
        return mb_substr($value, 0, 500);
    }

    /** @param array<string,string> $values */
    private function normalizeBilling429Backoff(array &$values): void
    {
        $normalized = ApiRhythmPolicyService::normalizeBilling429BackoffMinutes([
            $values['api.rhythm.billing_429_backoff_1_minutes'] ?? 30,
            $values['api.rhythm.billing_429_backoff_2_minutes'] ?? 120,
            $values['api.rhythm.billing_429_backoff_3_minutes'] ?? 360,
            $values['api.rhythm.billing_429_backoff_max_minutes'] ?? 720,
        ]);
        $values['api.rhythm.billing_429_backoff_1_minutes'] = (string) $normalized[1];
        $values['api.rhythm.billing_429_backoff_2_minutes'] = (string) $normalized[2];
        $values['api.rhythm.billing_429_backoff_3_minutes'] = (string) $normalized[3];
        $values['api.rhythm.billing_429_backoff_max_minutes'] = (string) $normalized[4];
    }

    private function requireSection(string $sectionKey): array
    {
        $section = $this->definitions->section($sectionKey);
        if ($section === null) {
            throw new InvalidArgumentException('La sección de configuración no existe.');
        }
        return $section;
    }

    private function stringValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return (string) $value;
    }
}
