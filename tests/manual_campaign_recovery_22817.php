<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$campaign = (string) file_get_contents($root . '/app/Services/ManualCampaignService.php');
$worker = (string) file_get_contents($root . '/app/Services/ResumableCampaignWorkerService.php');
$journal = (string) file_get_contents($root . '/app/Services/ExecutionJournalService.php');
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$check(
    str_contains($campaign, 'public function recoverInterruptedCampaignItem(int $campaignId): array')
        && str_contains($campaign, 'lease_expires_at<UTC_TIMESTAMP(3)')
        && str_contains($campaign, 'lease_generation=?'),
    'La recuperación de punteros debe exigir lease vencido y generación coincidente.'
);
$check(
    str_contains($campaign, "'remote_result_uncertain'")
        && str_contains($campaign, "'interrupted_before_remote'")
        && str_contains($campaign, 'reached_remote'),
    'La compensación debe separar interrupción local de resultado remoto incierto.'
);
$check(
    str_contains($campaign, 'public function compensateInterruptedClaim(array $item, int $attemptId): array')
        && str_contains($campaign, 'lease_owner=? AND lease_generation=?'),
    'Una excepción del worker debe liberar solo el claim exacto que todavía posee.'
);
$check(
    str_contains($campaign, 'public function isolateUncertainJournalResults(int $campaignId): int')
        && str_contains($campaign, 'a.state="uncertain"')
        && str_contains($worker, 'isolateUncertainJournalResults((int) $campaign[\'id\'])'),
    'Los resultados remotos inciertos deben aislarse por recurso sin pausar la campaña completa.'
);
$check(
    str_contains($campaign, 'public function reconcileItemAfterResolution(')
        && str_contains($campaign, "['retry', 'skip_campaign', 'return_to_queue', 'close_expected']")
        && str_contains($campaign, 'i.company_id=? AND i.meli_account_id=?'),
    'La reconciliación debe ser exacta, limitada a acciones conocidas y scoped por empresa/cuenta.'
);
$check(
    str_contains($campaign, "\$campaign['resolved_items'] = \$completedReal")
        && str_contains($campaign, "\$campaign['terminal_items'] = \$terminalItems")
        && str_contains($campaign, '$completedReal >= 3')
        && str_contains($campaign, 'CASE WHEN status="completed" THEN completed_units ELSE 0 END'),
    'Errores y omitidos no deben contarse como completados ni alimentar la ETA.'
);
$check(
    str_contains($campaign, "\$campaign['continues_with_attention']")
        && str_contains($campaign, "\$campaign['processing_paused']"),
    'La lectura debe distinguir campaña activa con incidencias de una campaña pausada.'
);
$check(
    str_contains($worker, 'recoverInterruptedCampaignItem((int) $campaign[\'id\'])')
        && str_contains($worker, 'compensateInterruptedClaim($lastItem, $lastAttemptId)')
        && !str_contains($worker, 'ManualCampaignService())->pause('),
    'El worker debe recuperar/compensar sin pausar toda la campaña por un recurso aislado.'
);
$check(
    !str_contains($journal, 'status=IF(uncertain_attempts+?>=3,"paused",status)')
        && str_contains($journal, 'next_action_at=IF(status="active",UTC_TIMESTAMP(3),next_action_at)'),
    'El diario no debe pausar globalmente una campaña ni dejarla fuera de la reconciliación por resultados inciertos.'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL ' . $failure . PHP_EOL);
    }
    exit(1);
}

fwrite(STDOUT, "PASS manual_campaign_recovery_22817\n");
