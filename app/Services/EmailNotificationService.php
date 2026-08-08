<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

final class EmailNotificationService
{
    public function enabled(): bool
    {
        $settings = new AppSettingsService();
        return $settings->bool('questions.email_enabled', false) && trim((string) $settings->get('questions.email_to', '')) !== '';
    }

    public function sendQuestionAlert(array $question): bool
    {
        if (!$this->enabled()) {
            return false;
        }
        $settings = new AppSettingsService();
        $to = trim((string) $settings->get('questions.email_to', ''));
        $subject = 'ERP Meli: nueva pregunta pendiente';
        $body = "Cuenta: " . ($question['account_name'] ?? $question['meli_account_id'] ?? '') . "\n"
            . "Publicacion: " . ($question['external_item_id'] ?? '') . "\n"
            . "Pregunta: " . ($question['text'] ?? '') . "\n"
            . "Fecha: " . ($question['asked_at'] ?? '') . "\n";
        $headers = 'From: ' . (Env::get('MAIL_FROM', 'no-reply@localhost') ?: 'no-reply@localhost');
        return @mail($to, $subject, $body, $headers);
    }
}
