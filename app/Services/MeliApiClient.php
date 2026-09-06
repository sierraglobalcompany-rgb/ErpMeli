<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Crypto;
use App\Core\Database;
use App\Core\Env;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use RuntimeException;
use Throwable;
use App\QueueCore\QueueCorePreRemoteBlockedException;

final class MeliApiClient implements MeliReadClientInterface
{
    private MeliHttpTransportInterface $transport;
    /** @var array{status:int,headers:array<string,string>,request_id:string,response_item_count:int,response_count_state:string}|null */
    private ?array $lastResponseMetadata = null;

    public function __construct(
        private readonly int $accountId,
        ?MeliHttpTransportInterface $transport = null
    ) {
        $this->transport = $transport ?? new CurlMeliHttpTransport();
    }

    public function get(string $path, array $query = [], array $meta = []): array
    {
        return $this->request('GET', $path, $query, false, $meta);
    }

    /**
     * Único POST técnico permitido fuera de las lecturas comerciales.
     * Conserva pacing, presupuesto, circuitos, telemetría y Retry-After sin
     * exponer secretos en logs.
     *
     * @param array<string,string> $data
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    public function exchangeOAuthToken(array $data, array $meta = []): array
    {
        // El intercambio OAuth también es transporte remoto. La parada física
        // debe bloquearlo antes de perfiles, presupuesto o cualquier intento.
        (new MeliEmergencyStopService())->assertAllowed();
        MeliEndpointRegistry::assertOAuthTokenExchange('POST', '/oauth/token');
        return $this->send(
            'POST',
            rtrim(Env::get('MELI_API_BASE', 'https://api.mercadolibre.com'), '/') . '/oauth/token',
            $data,
            [],
            false,
            true,
            array_replace(['job_type' => 'oauth', 'source' => PHP_SAPI === 'cli' ? 'cron' : 'web'], $meta)
        );
    }

    /**
     * Metadatos no sensibles de la respuesta anterior. Se exponen para que
     * procesos forenses puedan distinguir 200, 206 y campos omitidos.
     *
     * @return array{status:int,headers:array<string,string>,request_id:string,response_item_count:int,response_count_state:string}|null
     */
    public function lastResponseMetadata(): ?array
    {
        return $this->lastResponseMetadata;
    }

    public function request(string $method, string $path, array $data = [], bool $mutation = false, array $meta = []): array
    {
        MeliEndpointRegistry::assertDocumented($method, $path);
        $mutation = $mutation || strtoupper($method) !== 'GET';
        WriteGuard::assertAllowed($mutation);
        $meta = array_replace($meta, ApiExecutionMetadataContext::current());
        $requestGuard = new ApiGuardService();
        $requestGuard->assertMetadataScope($this->accountId, $meta);
        $requestGuard->assertAllowed($this->accountId, $method, $path);
        $token = $this->validToken();
        $url = rtrim(Env::get('MELI_API_BASE', 'https://api.mercadolibre.com'), '/') . '/' . ltrim($path, '/');
        return $this->send($method, $url, $data, ['Authorization: Bearer ' . $token], $mutation, false, $meta);
    }

    private function validToken(): string
    {
        $stmt = Database::connection()->prepare('SELECT * FROM meli_tokens WHERE meli_account_id = :account LIMIT 1');
        $stmt->execute(['account' => $this->accountId]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$token) {
            throw new RuntimeException('La cuenta no tiene un token OAuth.');
        }
        $skew = max(30, min(600, (new AppSettingsService())->int('oauth.token_expiry_skew_seconds', 120)));
        if ($this->tokenExpiresSoon((string) ($token['expires_at'] ?? ''), $skew)) {
            throw new OAuthRefreshRequiredException($this->accountId);
        }
        return Crypto::decrypt($token['access_token_encrypted']);
    }

    private function tokenExpiresSoon(string $expiresAt, int $skew): bool
    {
        $expiresAt = trim($expiresAt);
        if ($expiresAt === '') {
            return true;
        }
        try {
            $expiry = new DateTimeImmutable($expiresAt, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return true;
        }
        return $expiry->getTimestamp() <= time() + $skew;
    }

    /** @return array<string,mixed> */
    public function refreshOAuthToken(): array
    {
        return (new OAuthTokenRefreshService($this->accountId))->refresh(
            fn (array $refreshData): array => $this->send(
                'POST',
                rtrim(Env::get('MELI_API_BASE', 'https://api.mercadolibre.com'), '/') . '/oauth/token',
                [
                'grant_type' => 'refresh_token',
                'client_id' => Env::get('MELI_CLIENT_ID', ''),
                'client_secret' => Env::get('MELI_CLIENT_SECRET', ''),
                ] + $refreshData,
                [],
                false,
                true,
                ['job_type' => 'oauth', 'source' => PHP_SAPI === 'cli' ? 'cron' : 'web', 'account_id' => $this->accountId]
            )
        );
    }

    private function send(string $method, string $url, array $data, array $headers = [], bool $mutation = false, bool $form = false, array $meta = []): array
    {
        \App\QueueV4Clean\QueueV4CleanCycleBudget::assertActive();
        // El contexto del worker prevalece sobre etiquetas genéricas como
        // source=cron. Así cada llamada real puede atribuirse a su campaña.
        $meta = array_replace($meta, ApiExecutionMetadataContext::current());
        $method = strtoupper($method);
        $guard = new ApiGuardService();
        $budget = new ApiBudgetService();
        $rhythm = new ApiRhythmPolicyService();
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
            \App\QueueV4Clean\QueueV4CleanOAuthStageContext::OPERATION_PROFILE
        );
        $profile = (new MeliOperationProfileRegistry())->resolve($method, $path, $meta);
        $meta['operation_key'] = $profile['key'];
        $meta['load_class'] = $profile['load_class'];
        $meta['workload_units'] = $profile['workload_units'];
        try {
            \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
                \App\QueueV4Clean\QueueV4CleanOAuthStageContext::OWNERSHIP_GUARD
            );
            \App\QueueCore\QueueCoreOwnershipGuard::assertLegacyTransportAllowed(
                $method,
                $path,
                $meta
            );
            \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
                \App\QueueV4Clean\QueueV4CleanOAuthStageContext::METADATA_GUARD
            );
            $guard->assertMetadataScope($this->accountId, $meta);
            \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
                \App\QueueV4Clean\QueueV4CleanOAuthStageContext::API_GUARD
            );
            $guard->assertAllowed($this->accountId, $method, $path, $meta);
        } catch (\Throwable $blocked) {
            ApiExecutionMetadataContext::markRemoteAttempted();
            ApiExecutionMetadataContext::markRemoteBlocked();
            throw $blocked;
        }
        if (Database::connection()->inTransaction()) {
            throw new RuntimeException('Una consulta externa no puede ejecutarse dentro de una transacción MySQL activa.');
        }
        $source = (string) ($meta['source'] ?? '');
        MeliTransportSourcePolicy::assertAllowed($source, $method, $path);
        $singleDispatchAttempt = MeliTransportSourcePolicy::isSingleDispatch($source);
        $manualEmergencyCanary = (string) ($meta['source'] ?? '') === 'manual_emergency_canary';
        $manualEmergencyOAuthRefresh = (string) ($meta['source'] ?? '') === 'manual_emergency_oauth_refresh';
        $oauthControlPlane = MeliTransportSourcePolicy::requiresCurrentOAuthFence($source);
        $queueV4ReadContext = MeliTransportSourcePolicy::requiresQueueV4ReadFence($source);
        $cronV3RemoteContext = (string) ($meta['source'] ?? '') === 'cron_v3_remote';
        $queueCoreContext = (string) ($meta['source'] ?? '') === 'queue_core';
        $attempts = $singleDispatchAttempt ? 1 : $guard->maxAttempts($mutation, $method);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            // One server-owned identity per physical attempt, never per work item.
            $requestId = bin2hex(random_bytes(20));
            $meta['transport_request_id'] = $requestId;
            ApiExecutionMetadataContext::markRemoteAttempted();
            $budgetReservation = [];
            $rhythmPermit = [];
            try {
                ApiExecutionMetadataContext::claimRemoteCall();
                // Ritmo antes de presupuesto: una espera local no consume
                // presupuesto ni se presenta como transporte remoto.
                \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
                    \App\QueueV4Clean\QueueV4CleanOAuthStageContext::RHYTHM_RESERVATION
                );
                $rhythmPermit = $cronV3RemoteContext
                    ? ['enabled' => true, 'source' => 'cron_v3_rate_gate']
                    : $rhythm->reserve($this->accountId, $method, $path, $meta);
                if ($queueCoreContext && empty($rhythmPermit['enabled'])) {
                    throw new ApiRhythmDeferredException(
                        'Queue Core no iniciará HTTP sin su autoridad persistente de ritmo.',
                        gmdate('Y-m-d H:i:s', time() + 60),
                        'rhythm_authority_unavailable'
                    );
                }
                if ($queueV4ReadContext && empty($rhythmPermit['enabled'])) {
                    throw new ApiRhythmDeferredException(
                        'Queue V4 no iniciará HTTP sin su autoridad persistente de ritmo.',
                        gmdate('Y-m-d H:i:s', time() + 60),
                        'rhythm_authority_unavailable'
                    );
                }
                // Compatibilidad durante la ventana entre subir archivos y
                // aplicar la migración que crea la autoridad persistente.
                if (!$cronV3RemoteContext && empty($rhythmPermit['enabled'])) {
                    (new ApiPacingService())->reserve($this->accountId, $method, $path, $meta);
                }
                \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
                    \App\QueueV4Clean\QueueV4CleanOAuthStageContext::BUDGET_RESERVATION
                );
                $budgetReservation = MeliTransportSourcePolicy::usesPrimaryRhythmAuthority($source)
                    ? [
                        'allowed' => true,
                        'job_type' => (string) ($meta['job_type'] ?? ''),
                        'source' => $source,
                        'next_safe_at' => null,
                        'window_started_at' => null,
                        'window_seconds' => 0,
                        'web_counter_reserved' => false,
                        'scopes' => [],
                    ]
                    : $budget->reserve($this->accountId, $method, $path, $meta);
                $executionAttemptId = max(0, (int) ($meta['execution_attempt_id'] ?? 0));
                if ($executionAttemptId > 0) {
                    (new ExecutionJournalService())->budgetReserved($executionAttemptId);
                }
            } catch (ApiRhythmDeferredException $rhythmError) {
                $rhythm->release($rhythmPermit);
                ApiExecutionMetadataContext::markRemoteBlocked();
                $classification = [
                    'type' => 'api_rhythm_deferred',
                    'outcome_class' => 'policy_delay',
                    'reached_remote' => false,
                    'is_retryable' => true,
                    'is_app_blocked_signal' => false,
                    'recommendation' => 'El ERP continuará automáticamente en la próxima oportunidad segura.',
                    'next_safe_at' => $rhythmError->nextSafeAt,
                    'blocking_scope' => $rhythmError->blockingScope,
                ];
                $guard->recordRequest($this->accountId, $requestId, $method, $path, null, null, null, $attempt, true, $rhythmError->getMessage(), $classification, 'api_rhythm_deferred', $meta);
                throw $rhythmError;
            } catch (ApiBudgetExhaustedException $budgetError) {
                $rhythm->release($rhythmPermit);
                ApiExecutionMetadataContext::markRemoteBlocked();
                $classification = [
                    'type' => 'api_budget_exhausted',
                    'outcome_class' => 'policy_delay',
                    'reached_remote' => false,
                    'is_retryable' => true,
                    'is_app_blocked_signal' => false,
                    'recommendation' => 'Espere la próxima ventana segura o deje que cron continúe por presupuesto.',
                ];
                $guard->recordRequest($this->accountId, $requestId, $method, $path, null, null, null, $attempt, true, $budgetError->getMessage(), $classification, 'api_budget_exhausted', $meta);
                throw $budgetError;
            } catch (ApiBudgetInfrastructureException $budgetError) {
                $rhythm->release($rhythmPermit);
                ApiExecutionMetadataContext::markRemoteBlocked();
                $classification = [
                    'type' => 'api_budget_infrastructure',
                    'outcome_class' => 'local_failure',
                    'reached_remote' => false,
                    'is_retryable' => true,
                    'is_app_blocked_signal' => false,
                    'recommendation' => 'Revise la base de datos del ERP. La consulta no se envió a Mercado Libre.',
                ];
                $guard->recordRequest($this->accountId, $requestId, $method, $path, null, null, null, $attempt, true, $budgetError->getMessage(), $classification, 'api_budget_infrastructure', $meta);
                throw $budgetError;
            } catch (\Throwable $blocked) {
                $rhythm->release($rhythmPermit);
                ApiExecutionMetadataContext::markRemoteBlocked();
                throw $blocked;
            }
            $executionJournal = new ExecutionJournalService();
            $executionLeaseGeneration = max(0, (int) ($meta['execution_lease_generation'] ?? 0));
            $dispatchBoundaryCrossed = false;
            try {
                if (!$rhythm->isCurrent($rhythmPermit)) {
                    throw new ApiRhythmDeferredException(
                        'El permiso de ritmo cambió antes de iniciar el transporte.',
                        gmdate('Y-m-d H:i:s', time() + 1),
                        'rhythm_fence_stale'
                    );
                }
                // Segunda barrera para la carrera entre el guard del launcher y
                // el transporte. Solo aplica al worker V3: las lecturas canarias
                // manuales deben seguir siendo posibles con automatización parada.
                if (($cronV3RemoteContext || ($queueCoreContext && (string)($meta['queue_core_launcher']??'')==='cron_v4'))
                    && (new EmergencyControlService())->automationStopped()) {
                    if ($queueCoreContext) {
                        throw new QueueCorePreRemoteBlockedException(
                            'Automation Stop denied Queue Core before physical transport.'
                        );
                    }
                    throw new RuntimeException('La automatización se detuvo antes del transporte remoto.');
                }
                // La ventana puede agotarse después de reservar ritmo y
                // presupuesto. Se calcula dentro de la misma barrera que el
                // transporte para devolver ambas reservas si HTTP no inició.
                $timeouts = CronDeadlineContext::curlTimeouts();
                if ($executionAttemptId > 0
                    && !$executionJournal->dispatchStarted($executionAttemptId, $executionLeaseGeneration)) {
                    throw new RuntimeException('La reserva exacta cambió antes de iniciar el transporte remoto.');
                }
                // El permiso cambia reserved -> dispatched inmediatamente
                // antes de entregar el control al transporte. Después de este
                // punto no puede reutilizarse ni confirmarse con otra generación.
                try {
                    $rhythmDispatched = $rhythm->dispatched($rhythmPermit);
                } catch (Throwable $rhythmFailure) {
                    if ($executionAttemptId > 0) {
                        $executionJournal->dispatchCancelledBeforeRemote($executionAttemptId, $executionLeaseGeneration);
                    }
                    throw $rhythmFailure;
                }
                if (!$rhythmDispatched) {
                    if ($executionAttemptId > 0) {
                        $executionJournal->dispatchCancelledBeforeRemote($executionAttemptId, $executionLeaseGeneration);
                    }
                    throw new ApiRhythmDeferredException(
                        'El permiso de ritmo venció antes de iniciar el transporte.',
                        gmdate('Y-m-d H:i:s', time() + 1),
                        'rhythm_fence_stale'
                    );
                }
                // Legacy callers have no Queue Core attempt journal. Queue
                // Core decides this boundary exclusively from the persisted
                // physical marker written next to curl_exec by its transport.
                $dispatchBoundaryCrossed = true;
                \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
                    \App\QueueV4Clean\QueueV4CleanOAuthStageContext::TRANSPORT_PREPARE
                );
                $transportResult = ApiExecutionMetadataContext::withTransportMetadata(
                    [
                        'transport_meli_account_id' => $this->accountId,
                        'transport_operation_key' => (string) $profile['key'],
                        'transport_method' => $method,
                        'transport_endpoint' => $path,
                        'transport_request_id' => $requestId,
                    ],
                    fn (): array => $this->transport->request(
                        $method,
                        $url,
                        $data,
                        array_merge($headers, [
                            'Accept: application/json',
                            'X-Request-Id: ' . $requestId,
                            $form ? 'Content-Type: application/x-www-form-urlencoded' : 'Content-Type: application/json',
                        ]),
                        $form,
                        $timeouts
                    )
                );
            } catch (Throwable $transportBlocked) {
                // A failed certification cannot be downgraded by a later row
                // read (for example a rollback whose acknowledgement was lost).
                if ($transportBlocked instanceof RemoteResultUncertainException) {
                    \App\QueueV4Clean\QueueV4CleanCycleBudget::stop('remote_result_uncertain');
                    throw $transportBlocked;
                }
                if ($source === 'queue_v4_clean_readiness'
                    && (!$dispatchBoundaryCrossed || \App\QueueV4Clean\QueueV4CleanTransportContext::consumePreTransportCancellation(
                        $requestId, (int) ($meta['company_id'] ?? 0), $this->accountId
                    ))) {
                    // cURL owns the physical reservation. This one-shot private
                    // receipt refunds only the existing budget/rhythm reservations.
                    // Consume evidence first: an ambiguous acknowledgement never
                    // reaches another catch that could refund either one again.
                    try {
                        $budget->releaseReservation($budgetReservation, true);
                        $rhythm->cancelBeforeTransport($rhythmPermit, true);
                    } catch (Throwable) {
                        \App\QueueV4Clean\QueueV4CleanCycleBudget::stop('remote_result_uncertain');
                        throw new RemoteResultUncertainException($requestId);
                    }
                    ApiExecutionMetadataContext::markRemoteBlocked();
                    throw $transportBlocked;
                }
                if ($queueCoreContext) {
                    // Do not infer physical dispatch merely because control
                    // was handed to the transport object. curl_init, option
                    // validation, API Stop and Automation Stop can still fail
                    // before curl_exec. The attempt journal is the authority.
                    try {
                        $queueCorePhysicalStarted = \App\QueueCore\QueueCoreDispatchFence::physicalTransportRecorded();
                    } catch (Throwable) {
                        // If the authority itself is unavailable, fail closed:
                        // a retry cannot be proven safe.
                        $queueCorePhysicalStarted = true;
                    }
                    if (!$queueCorePhysicalStarted) {
                        $compensationFailure = null;
                        try {
                            $budget->releaseReservation($budgetReservation,true);
                        } catch (Throwable $failure) {
                            $compensationFailure = $failure;
                        }
                        try {
                            $rhythm->cancelBeforeTransport($rhythmPermit,true);
                        } catch (Throwable $failure) {
                            $compensationFailure ??= $failure;
                        }
                        if ($executionAttemptId > 0) {
                            try {
                                $executionJournal->dispatchCancelledBeforeRemote($executionAttemptId,$executionLeaseGeneration);
                            } catch (Throwable $failure) {
                                $compensationFailure ??= $failure;
                            }
                        }
                        ApiExecutionMetadataContext::markRemoteBlocked();
                        if ($compensationFailure !== null) {
                            \App\QueueV4Clean\QueueV4CleanCycleBudget::stop('remote_result_uncertain');
                            throw new RemoteResultUncertainException($requestId);
                        }
                        throw $transportBlocked;
                    }
                }
                if (MeliTransportSourcePolicy::requiresCurrentOAuthFence($source)) {
                    $oauthFence = \App\QueueV4Clean\QueueV4CleanOAuthDispatchFence::state([
                        'id' => (int) ($meta['oauth_operation_id'] ?? 0),
                        'company_id' => (int) ($meta['company_id'] ?? 0),
                        'meli_account_id' => (int) ($meta['account_id'] ?? 0),
                    ]);
                    if ($oauthFence['dispatch_state'] === 'NOT_DISPATCHED') {
                        try {
                            $budget->releaseReservation($budgetReservation, true);
                            $rhythm->cancelBeforeTransport($rhythmPermit, true);
                        } catch (Throwable) { \App\QueueV4Clean\QueueV4CleanCycleBudget::stop('remote_result_uncertain'); throw new RemoteResultUncertainException($requestId); }
                        ApiExecutionMetadataContext::markRemoteBlocked();
                        throw $transportBlocked;
                    }
                }
                if ($queueV4ReadContext) {
                    try {
                        $queueV4Fence = \App\QueueV4Clean\QueueV4CleanDispatchFence::state($meta);
                    } catch (Throwable) {
                        $queueV4Fence = ['dispatch_state' => 'UNKNOWN', 'http_status' => null];
                    }
                    $queueV4DispatchState = (string) ($queueV4Fence['dispatch_state'] ?? 'UNKNOWN');
                    if ($queueV4DispatchState === 'NOT_DISPATCHED') {
                        try {
                            $budget->releaseReservation($budgetReservation, true);
                            $rhythm->cancelBeforeTransport($rhythmPermit, true);
                        } catch (Throwable) { \App\QueueV4Clean\QueueV4CleanCycleBudget::stop('remote_result_uncertain'); throw new RemoteResultUncertainException($requestId); }
                        ApiExecutionMetadataContext::markRemoteBlocked();
                        if ($transportBlocked instanceof ApiRhythmDeferredException
                            || $transportBlocked instanceof ApiBudgetExhaustedException
                            || $transportBlocked instanceof ApiManualPauseException
                            || $transportBlocked instanceof CronDeadlineDeferredException) {
                            throw $transportBlocked;
                        }
                        throw new QueueV4PreTransportDeferredException(
                            gmdate('Y-m-d H:i:s', time() + 60)
                        );
                    }
                    // Missing/failed authority cannot certify zero. Retain the
                    // reservation and require review just like uncertain wire I/O.
                    ApiExecutionMetadataContext::markRemoteDispatched();
                    $classification = [
                        'type' => 'remote_result_uncertain',
                        'outcome_class' => 'action_required',
                        'reached_remote' => true,
                        'is_retryable' => false,
                        'is_app_blocked_signal' => false,
                        'recommendation' => 'Revise el trabajo antes de autorizar otro intento.',
                    ];
                    if (!MeliTransportSourcePolicy::usesPrimaryRhythmAuthority($source)) {
                        $budget->recordResult($this->accountId, $method, $path, null, null, $meta, $classification);
                    }
                    $guard->recordRequest(
                        $this->accountId,
                        $requestId,
                        $method,
                        $path,
                        $queueV4Fence['http_status'],
                        null,
                        null,
                        $attempt,
                        true,
                        'La lectura GET cruzó la frontera física sin un resultado local utilizable.',
                        $classification,
                        'remote_result_uncertain',
                        $meta
                    );
                    \App\QueueV4Clean\QueueV4CleanCycleBudget::stop('remote_result_uncertain');
                    throw new RemoteResultUncertainException($requestId);
                }
                // La barrera del transporte OAuth se ejecuta dentro del
                // adaptador pero todavía antes de curl_init/curl_exec. Una
                // denegación de emergencia en ese punto certifica cero HTTP,
                // aunque el cliente ya hubiera cedido control al adaptador.
                if ($manualEmergencyOAuthRefresh
                    && $transportBlocked instanceof ApiManualPauseException) {
                    $budget->releaseReservation($budgetReservation);
                    $rhythm->cancelBeforeTransport($rhythmPermit);
                    if ($executionAttemptId > 0) {
                        $executionJournal->dispatchCancelledBeforeRemote(
                            $executionAttemptId,
                            $executionLeaseGeneration
                        );
                    }
                    ApiExecutionMetadataContext::markRemoteBlocked();
                    throw $transportBlocked;
                }
                if (!$dispatchBoundaryCrossed) {
                    // La frontera remota no se cruzó: ambas reservas pueden
                    // devolverse sin riesgo de duplicar una consulta.
                    $budget->releaseReservation($budgetReservation);
                    $rhythm->cancelBeforeTransport($rhythmPermit);
                    if ($executionAttemptId > 0) {
                        $executionJournal->dispatchCancelledBeforeRemote(
                            $executionAttemptId,
                            $executionLeaseGeneration
                        );
                    }
                    ApiExecutionMetadataContext::markRemoteBlocked();
                    throw $transportBlocked;
                }

                // Una excepción después de ceder el control al transporte no
                // demuestra que la solicitud no salió. Consumimos el permiso
                // de forma conservadora y bloqueamos el reintento automático.
                ApiExecutionMetadataContext::markRemoteDispatched();
                $classification = [
                    'type' => 'remote_result_uncertain',
                    'outcome_class' => 'action_required',
                    'reached_remote' => true,
                    'is_retryable' => false,
                    'is_app_blocked_signal' => false,
                    'recommendation' => 'Revise el trabajo exacto antes de autorizar otro intento.',
                ];
                if (!MeliTransportSourcePolicy::usesPrimaryRhythmAuthority($source)) {
                    $budget->recordResult($this->accountId, $method, $path, null, null, $meta, $classification);
                }
                $guard->recordRequest(
                    $this->accountId,
                    $requestId,
                    $method,
                    $path,
                    null,
                    null,
                    null,
                    $attempt,
                    true,
                    'El transporte comenzó, pero no devolvió un resultado local verificable.',
                    $classification,
                    'remote_result_uncertain',
                    $meta
                );
                \App\QueueV4Clean\QueueV4CleanCycleBudget::stop('remote_result_uncertain');
                throw new RemoteResultUncertainException($requestId);
            }
            // La telemetría en memoria certifica transporte solo después de
            // que el adaptador aceptó y devolvió el control. La protección
            // contra cortes vive en el journal persistido antes de la llamada.
            ApiExecutionMetadataContext::markRemoteDispatched();
            $status = (int) $transportResult['status'];
            if ($status === 429) { \App\QueueV4Clean\QueueV4CleanCycleBudget::stop('remote_429_global_pause'); }
            $curlError = (string) $transportResult['curl_error'];
            if ($queueCoreContext && $status > 0 && $curlError === '') {
                \App\QueueCore\QueueCoreDispatchFence::responseKnown($status);
            }
            $durationMs = (int) $transportResult['duration_ms'];
            $decoded = $transportResult['body'];
            $responseHeaders = $transportResult['headers'];
            $wireBytes = max(0, $transportResult['wire_bytes']);
            $decodedBytes = max(0, $transportResult['decoded_bytes']);
            $retryAfter = HttpRetryAfterParser::seconds($responseHeaders['retry-after'] ?? null);
            if ($status <= 0 || $curlError !== '') {
                $classification = [
                    'type' => 'remote_result_uncertain',
                    'outcome_class' => 'action_required',
                    'reached_remote' => true,
                    'is_retryable' => false,
                    'is_app_blocked_signal' => false,
                    'recommendation' => 'Revise el trabajo antes de autorizar otro intento.',
                ];
                if (!MeliTransportSourcePolicy::usesPrimaryRhythmAuthority($source)) {
                    $budget->recordResult($this->accountId, $method, $path, null, null, $meta, $classification);
                }
                $guard->recordRequest(
                    $this->accountId, $requestId, $method, $path, null, $durationMs, null,
                    $attempt, false,
                    'La solicitud inició transporte, pero no produjo una respuesta local verificable.',
                    $classification, 'remote_result_uncertain', $meta
                );
                \App\QueueV4Clean\QueueV4CleanCycleBudget::stop('remote_result_uncertain');
                throw new RemoteResultUncertainException($requestId);
            }
            // Queue Core treats 206 as an incomplete page, never as a
            // successful terminal receipt. The known response remains
            // retryable in a new fenced attempt without advancing cursors.
            if ($curlError === '' && $status >= 200 && $status < 300
                && !($queueCoreContext && $status === 206)) {
                $telemetryMeta = $meta;
                $responseCount = $this->responseItemCount($decoded, $meta, $status);
                $telemetryMeta['response_item_count'] = $responseCount['count'];
                $telemetryMeta['response_count_state'] = $responseCount['state'];
                $telemetryMeta['response_resource_unit'] = $responseCount['unit'];
                $safeHeaders = [];
                foreach (['x-content-missing', 'retry-after'] as $allowedHeader) {
                    if (isset($responseHeaders[$allowedHeader])) {
                        $safeHeaders[$allowedHeader] = mb_substr((string) $responseHeaders[$allowedHeader], 0, 500);
                    }
                }
                $this->lastResponseMetadata = [
                    'status' => $status,
                    'headers' => $safeHeaders,
                    'request_id' => $requestId,
                    'response_item_count' => (int) $responseCount['count'],
                    'response_count_state' => (string) $responseCount['state'],
                ];
                $guard->recordRequest($this->accountId, $requestId, $method, $path, $status, $durationMs, $retryAfter, $attempt, false, null, null, null, $meta);
                (new MeliOperationTelemetryService())->record($this->accountId, $requestId, $profile, $durationMs, $wireBytes, $decodedBytes, $status, true, $telemetryMeta);
                if (!MeliTransportSourcePolicy::usesPrimaryRhythmAuthority($source)) {
                    $budget->recordResult($this->accountId, $method, $path, $status, $retryAfter, $meta);
                }
                $rhythm->finalizeKnownResult($rhythmPermit, $status, $retryAfter);
                return $decoded;
            }
            $safeMessage = mb_substr(Logger::redactString((string) ($decoded['message'] ?? $curlError ?: 'Error de Mercado Libre API')), 0, 1000);
            $errorCode = isset($decoded['error']) ? mb_substr(Logger::redactString((string) $decoded['error']), 0, 100) : null;
            $safeDecoded = Logger::redact($decoded);
            $classification = ApiErrorClassifier::classify($status ?: null, $errorCode, $safeMessage, $decoded, $curlError);
            if ($manualEmergencyCanary) {
                // El cuerpo de /users/me solo existe en memoria para clasificar
                // esta respuesta. Nunca cruza la frontera de observabilidad.
                $errorCode = $this->emergencyCanaryErrorCode($status, $curlError);
                $safeMessage = $this->emergencyCanarySafeMessage($errorCode);
                $safeDecoded = [];
                $classification = [
                    'type' => strtolower($errorCode),
                    'is_retryable' => false,
                    'is_app_blocked_signal' => false,
                    'recommendation' => 'Mantenga Mercado Libre bloqueado y revise la referencia canaria local.',
                ];
            } elseif ($manualEmergencyOAuthRefresh || $oauthControlPlane) {
                // La respuesta OAuth puede contener credenciales incluso en un
                // error. Solo se conserva una clase segura y nunca el body.
                $oauthError = isset($decoded['error'])
                    ? mb_substr(Logger::redactString((string) $decoded['error']), 0, 100)
                    : null;
                $errorCode = $this->emergencyOAuthRefreshErrorCode($status, $curlError);
                $safeMessage = $this->emergencyOAuthRefreshSafeMessage($errorCode);
                $safeDecoded = $oauthControlPlane && $oauthError !== null
                    ? ['error' => $oauthError]
                    : [];
                $classification = [
                    'type' => strtolower($errorCode),
                    'is_retryable' => false,
                    'is_app_blocked_signal' => false,
                    'recommendation' => 'Mantenga Mercado Libre y la automatización bloqueados; revise la referencia OAuth local.',
                ];
            }
            $classification['reached_remote'] = true;
            if ($status === 404 && str_contains($path, '/description')) {
                $classification['outcome_class'] = 'expected_absence';
            }
            if ($status === 404 && preg_match('#^/questions/[^/]+$#', $path) === 1) {
                $classification['outcome_class'] = 'expected_absence';
                $classification['actionable'] = false;
                $classification['risk_signal'] = false;
            }
            if ($status === 404 && preg_match('#^/packs/[^/]+$#', $path) === 1) {
                $classification['outcome_class'] = 'expected_absence';
                $classification['actionable'] = false;
                $classification['risk_signal'] = false;
                $classification['recommendation'] = 'Revise la integridad local de la venta; no existe riesgo de bloqueo.';
            }
            // A known non-2xx response remains observable to the caller. This
            // is deliberately absent for pre-transport deferrals, so callers
            // cannot present a synthetic HTTP response as a remote one.
            $safeHeaders = [];
            if (isset($responseHeaders['retry-after'])) {
                $safeHeaders['retry-after'] = mb_substr((string) $responseHeaders['retry-after'], 0, 500);
            }
            $this->lastResponseMetadata = [
                'status' => $status,
                'headers' => $safeHeaders,
                'request_id' => $requestId,
                'response_item_count' => 0,
                'response_count_state' => 'unknown',
            ];
            $guard->recordRequest($this->accountId, $requestId, $method, $path, $status ?: null, $durationMs, $retryAfter, $attempt, false, $safeMessage, $classification, $errorCode, $meta);
            $errorTelemetryMeta = $meta;
            $errorTelemetryMeta['response_item_count'] = 0;
            (new MeliOperationTelemetryService())->record($this->accountId, $requestId, $profile, $durationMs, $wireBytes, $decodedBytes, $status ?: null, true, $errorTelemetryMeta);
            if (!MeliTransportSourcePolicy::usesPrimaryRhythmAuthority($source)) {
                $budget->recordResult($this->accountId, $method, $path, $status ?: null, $retryAfter, $meta, $classification);
            }
            $rhythm->finalizeKnownResult($rhythmPermit, $status ?: null, $retryAfter);
            $nextSafeAtForAlert = $status === 429 ? $rhythm->rateLimitNextSafeAt($rhythmPermit, $retryAfter) : null;
            $this->notifyCriticalApiIncident(
                $method,
                $path,
                $status,
                $retryAfter,
                $requestId,
                $meta,
                $safeMessage,
                $nextSafeAtForAlert
            );
            if (MeliTransportSourcePolicy::usesQueueRateLimitDeferral($source) && $status === 429) {
                throw new ApiRhythmDeferredException(
                    'La cola respetará la autoridad durable del bloqueo remoto.',
                    $nextSafeAtForAlert ?? $rhythm->rateLimitNextSafeAt($rhythmPermit, $retryAfter),
                    'remote_429_global_pause',
                    true
                );
            }
            if ($attempt < $attempts && ($status === 429 || $status >= 500 || $curlError !== '')) {
                $delay = $guard->retryDelaySeconds($attempt, $status, $retryAfter);
                if (($delay * 1000) > (int) $rhythm->configuration()['short_wait_ceiling_ms']) {
                    $guard->afterFailure($this->accountId, $method, $path, $status, $retryAfter, $safeMessage, $classification);
                    throw new ApiRhythmDeferredException(
                        'El reintento continuará en otro ciclo para no inmovilizar PHP.',
                        gmdate('Y-m-d H:i:s', time() + $delay),
                        $status === 429 ? 'retry_after' : 'remote_backoff',
                        true
                    );
                }
                usleep(max(0, $delay * 1000000));
                continue;
            }
            $guard->afterFailure($this->accountId, $method, $path, $status, $retryAfter, $safeMessage, $classification);
            $this->logApiError(
                $requestId,
                $method,
                $path,
                $status,
                $safeMessage,
                $safeDecoded,
                ($manualEmergencyCanary || $manualEmergencyOAuthRefresh || $oauthControlPlane) ? $errorCode : null,
                $manualEmergencyCanary || $manualEmergencyOAuthRefresh || $oauthControlPlane
            );
            if (($queueCoreContext || MeliTransportSourcePolicy::usesQueueRateLimitDeferral($source))
                && ($status === 429 || $retryAfter !== null)) {
                $nextSafeAt = $status === 429
                    ? $rhythm->rateLimitNextSafeAt($rhythmPermit, $retryAfter)
                    : gmdate('Y-m-d H:i:s', time() + max(1, $guard->retryDelaySeconds($attempt, $status, $retryAfter)));
                throw new ApiRhythmDeferredException(
                    'La cola respetará la próxima oportunidad indicada por la protección remota.',
                    $nextSafeAt,
                    $status === 429 ? 'remote_429_global_pause' : 'remote_backoff',
                    true
                );
            }
            throw new MeliApiException($safeMessage, $status ?: null, $requestId, $safeDecoded);
        }
        throw new MeliApiException('Error de API no recuperable.', null, $requestId);
    }

    /** @param array<string,mixed> $meta */
    private function notifyCriticalApiIncident(
        string $method,
        string $path,
        int $status,
        ?int $retryAfter,
        string $requestId,
        array $meta,
        string $safeMessage,
        ?string $nextSafeAt
    ): void {
        if (!($status === 429 || in_array($status, [401, 403], true) || $status >= 500)) {
            return;
        }
        try {
            (new CriticalApiAlertEmailService())->notifyApiIncident([
                'meli_account_id' => $this->accountId,
                'method' => $method,
                'endpoint_path' => $path,
                'endpoint_key' => $meta['endpoint_key'] ?? $path,
                'http_status' => $status,
                'retry_after' => $retryAfter,
                'request_id' => $requestId,
                'execution_source' => $meta['source'] ?? $meta['execution_source'] ?? '',
                'job_type' => $meta['job_type'] ?? '',
                'source_work_id' => $meta['source_work_id'] ?? '',
                'operation_key' => $meta['operation_key'] ?? $meta['profile_key'] ?? '',
                'company_id' => $meta['company_id'] ?? null,
                'next_safe_at' => $nextSafeAt,
                'safe_message' => $safeMessage,
            ]);
        } catch (Throwable $error) {
            Logger::write('warning', 'Alerta crítica API no bloqueó el worker.', [
                'request_id' => $requestId,
                'status' => $status,
                'error' => get_class($error),
            ]);
        }
    }

    private function emergencyOAuthRefreshErrorCode(int $status, string $curlError): string
    {
        if ($curlError !== '' || $status <= 0) {
            return 'EMERGENCY_OAUTH_TRANSPORT_ERROR';
        }
        return match (true) {
            $status === 401 => 'EMERGENCY_OAUTH_HTTP_401',
            $status === 403 => 'EMERGENCY_OAUTH_HTTP_403',
            $status === 429 => 'EMERGENCY_OAUTH_HTTP_429',
            $status >= 500 => 'EMERGENCY_OAUTH_HTTP_5XX',
            default => 'EMERGENCY_OAUTH_HTTP_ERROR',
        };
    }

    private function emergencyOAuthRefreshSafeMessage(string $errorCode): string
    {
        return match ($errorCode) {
            'EMERGENCY_OAUTH_HTTP_401', 'EMERGENCY_OAUTH_HTTP_403' => 'Mercado Libre rechazó la renovación OAuth.',
            'EMERGENCY_OAUTH_HTTP_429' => 'Mercado Libre limitó temporalmente la renovación OAuth.',
            'EMERGENCY_OAUTH_HTTP_5XX' => 'Mercado Libre no pudo completar la renovación OAuth.',
            'EMERGENCY_OAUTH_TRANSPORT_ERROR' => 'No se obtuvo una respuesta verificable durante la renovación OAuth.',
            default => 'La renovación OAuth no fue aceptada.',
        };
    }

    private function logApiError(
        string $requestId,
        string $method,
        string $path,
        int $status,
        string $message,
        array $response,
        ?string $normalizedErrorCode = null,
        bool $omitResponse = false
    ): void
    {
        try {
            $responseError = isset($response['error']) ? (string) $response['error'] : null;
            $presented = (new ApiHealthSafeMessageService())->present($message, $normalizedErrorCode ?? $responseError);
            $stmt = Database::connection()->prepare('INSERT INTO api_error_logs (meli_account_id, request_id, method, endpoint_path, http_status, error_code, safe_message, response_json) VALUES (:account,:request,:method,:path,:status,:code,:message,:response)');
            $stmt->execute([
                'account' => $this->accountId,
                'request' => $requestId,
                'method' => $method,
                'path' => $path,
                'status' => $status ?: null,
                'code' => $normalizedErrorCode !== null
                    ? mb_substr($normalizedErrorCode, 0, 100)
                    : ($responseError !== null ? mb_substr(Logger::redactString($responseError), 0, 100) : null),
                'message' => $presented['safe_message'],
                // El canario manual nunca persiste el cuerpo remoto, ni siquiera
                // una versión redactada. NULL constituye la frontera contractual.
                'response' => $omitResponse
                    ? null
                    : json_encode(Logger::redact($response), JSON_UNESCAPED_UNICODE),
            ]);
        } catch (Throwable) {
            Logger::write('error', 'Fallo de Mercado Libre API', ['request_id' => $requestId, 'status' => $status, 'path' => $path]);
        }
    }

    private function emergencyCanaryErrorCode(int $status, string $curlError): string
    {
        if ($curlError !== '') {
            return 'CANARY_CURL_ERROR';
        }
        return match (true) {
            $status === 401 => 'CANARY_REMOTE_401',
            $status === 403 => 'CANARY_REMOTE_403',
            $status === 429 => 'CANARY_REMOTE_429',
            $status >= 500 => 'CANARY_REMOTE_5XX',
            default => 'CANARY_REMOTE_HTTP_ERROR',
        };
    }

    private function emergencyCanarySafeMessage(string $errorCode): string
    {
        return match ($errorCode) {
            'CANARY_REMOTE_401' => 'La prueba canaria recibió una autorización rechazada.',
            'CANARY_REMOTE_403' => 'La prueba canaria recibió un acceso denegado.',
            'CANARY_REMOTE_429' => 'La prueba canaria recibió una protección de ritmo.',
            'CANARY_REMOTE_5XX' => 'La prueba canaria recibió un fallo temporal remoto.',
            'CANARY_CURL_ERROR' => 'La prueba canaria no pudo confirmar una respuesta HTTP.',
            default => 'La prueba canaria recibió una respuesta HTTP no aprobada.',
        };
    }

    /** @param array<string,mixed>|list<mixed> $decoded @param array<string,mixed> $meta */
    /** @return array{count:int,state:string,unit:?string} */
    private function responseItemCount(array $decoded, array $meta, int $httpStatus): array
    {
        // 206 siempre representa una respuesta parcial, incluso si el payload
        // ya contiene algunos recursos o el caller propuso un conteo. Esos
        // recursos pueden observarse, pero nunca certifican una rampa estable.
        if ($httpStatus === 206) {
            $partial = $this->responseItemCount($decoded, $meta, 200);
            $partial['state'] = 'partial';
            return $partial;
        }
        if (array_key_exists('response_item_count', $meta)) {
            return [
                'count' => max(0, (int) $meta['response_item_count']),
                'state' => (string) ($meta['response_count_state'] ?? 'complete'),
                'unit' => isset($meta['response_resource_unit']) ? (string) $meta['response_resource_unit'] : null,
            ];
        }
        if (($meta['response_count_strategy'] ?? '') === 'billing_orders') {
            $count = $this->billingOrderCount($decoded, (string) ($meta['expected_resource_ids'] ?? ''));
            return [
                'count' => $count,
                'state' => $this->hasPendingResponseState($decoded) ? 'partial' : 'complete',
                'unit' => 'orders',
            ];
        }
        if ($this->hasPendingResponseState($decoded)) {
            return ['count' => 0, 'state' => 'partial', 'unit' => null];
        }
        $operation = (string) ($meta['operation_key'] ?? 'unknown_read');
        if ($operation === 'oauth') {
            return ['count' => 0, 'state' => 'complete', 'unit' => null];
        }
        $exactUnits = [
            'order_exact' => 'orders',
            'shipment_exact' => 'shipments',
            'pack_exact' => 'packs',
            'question_exact' => 'questions',
            'claim_exact' => 'claims',
            'item_detail' => 'items',
            'item_description' => 'items',
            'item_stock' => 'items',
        ];
        if (isset($exactUnits[$operation])) {
            return [
                'count' => $decoded === [] ? 0 : 1,
                'state' => 'complete',
                'unit' => $exactUnits[$operation],
            ];
        }
        $collectionContracts = [
            'orders_search' => ['results', 'orders'],
            'sales_audit' => ['results', 'orders'],
            'items_discovery' => ['results', 'items'],
            'questions_search' => ['questions', 'questions'],
            'claims_search' => ['data', 'claims'],
        ];
        $collection = $collectionContracts[$operation] ?? null;
        $collectionKey = $collection[0] ?? null;
        $resourceUnit = $collection[1] ?? null;
        if ($collectionKey === null || !isset($decoded[$collectionKey]) || !is_array($decoded[$collectionKey])) {
            return ['count' => 0, 'state' => 'unknown', 'unit' => null];
        }
        return [
            'count' => count($decoded[$collectionKey]),
            'state' => 'complete',
            'unit' => $resourceUnit,
        ];
    }

    /** @param array<string,mixed>|list<mixed> $payload */
    private function billingOrderCount(array $payload, string $expectedCsv): int
    {
        if ($this->hasPendingResponseState($payload)) {
            return 0;
        }
        $expected = array_fill_keys(array_values(array_filter(
            array_map('trim', explode(',', $expectedCsv)),
            static fn (string $id): bool => $id !== ''
        )), true);
        if ($expected === []) {
            return 0;
        }
        $ids = [];
        $walk = static function (mixed $node) use (&$walk, &$ids, $expected): void {
            if (!is_array($node)) {
                return;
            }
            if (!array_is_list($node)) {
                foreach (['order_id', 'orderId', 'external_order_id', 'externalOrderId', 'sale_id', 'saleId'] as $key) {
                    if (isset($node[$key]) && (is_int($node[$key]) || is_string($node[$key]))) {
                        $value = trim((string) $node[$key]);
                        if (isset($expected[$value])) {
                            $ids[$value] = true;
                        }
                    }
                }
            }
            foreach ($node as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($payload);
        return count($ids);
    }

    /** @param array<string,mixed>|list<mixed> $payload */
    private function hasPendingResponseState(array $payload): bool
    {
        foreach (['status', 'state'] as $key) {
            if (isset($payload[$key])
                && in_array(strtolower(trim((string) $payload[$key])), ['processing', 'pending', 'queued'], true)) {
                return true;
            }
        }
        return false;
    }
}
