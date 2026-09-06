<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class CurlMeliHttpTransport implements MeliHttpTransportInterface
{
    public function request(
        string $method,
        string $url,
        array $data,
        array $headers,
        bool $form,
        array $timeouts
    ): array {
        \App\QueueV4Clean\QueueV4CleanCycleBudget::assertActive();
        if ((string) (ApiExecutionMetadataContext::current()['source'] ?? '') === MeliTransportSourcePolicy::QUEUE_V4_OAUTH) {
            $capabilities = (new MeliCliRuntimeCapabilityService())->inspect();
            if (!(new MeliCliRuntimeCapabilityService())->oauthReady($capabilities)) {
                throw new RuntimeException('queue_v4_clean_oauth_cli_runtime_capability_missing');
            }
        }
        // Última barrera independiente del llamador: nunca abrir cURL hacia
        // Mercado Libre mientras exista la parada local de emergencia.
        $emergency = new MeliEmergencyStopService();
        $method = strtoupper($method);
        $executionSource=(string)(ApiExecutionMetadataContext::current()['source']??'');
        MeliTransportSourcePolicy::assertAllowed($executionSource, $method, parse_url($url, PHP_URL_PATH) ?: '/');
        if($executionSource==='queue_core'){
            // Read the physical stop without consuming a canary yet. The
            // authoritative check/claim is repeated immediately before cURL.
            $emergency->assertAllowed();
            // This is the Queue Core physical boundary. No DB lease may be
            // consumed after cURL is initialized or by a stale worker.
            \App\QueueCore\QueueCoreDispatchFence::beforeTransport(
                $method,
                parse_url($url,PHP_URL_PATH)?:'/'
            );
        }else{
            $emergency->assertTransportAllowed($method, $url);
        }
        \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
            \App\QueueV4Clean\QueueV4CleanOAuthStageContext::CURL_INIT
        );
        $ch = curl_init();
        if ($ch === false) {
            throw new RuntimeException('No se pudo inicializar cURL para consultar Mercado Libre.');
        }

        $urlWithQuery = $method === 'GET' && $data
            ? $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($data)
            : $url;
        $body = $form ? http_build_query($data) : json_encode($data, JSON_UNESCAPED_SLASHES);
        $responseHeaders = [];
        $options = [
            CURLOPT_URL => $urlWithQuery,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => max(1, $timeouts['timeout']),
            CURLOPT_CONNECTTIMEOUT => max(1, $timeouts['connect_timeout']),
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$key, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($key))] = trim($value);
                }
                return strlen($line);
            },
        ];
        $emergencySource = (string) (ApiExecutionMetadataContext::current()['source'] ?? '');
        if (MeliTransportSourcePolicy::blocksRedirects($emergencySource)) {
            // Un redirect también sería otra solicitud física. El canario no
            // puede seguirlo, ni siquiera cuando el servidor responda 301/302.
            $options[CURLOPT_FOLLOWLOCATION] = false;
            $options[CURLOPT_MAXREDIRS] = 0;
        }
        \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
            \App\QueueV4Clean\QueueV4CleanOAuthStageContext::CURL_OPTIONS
        );
        if (!curl_setopt_array($ch, $options)) {
            throw new RuntimeException('queue_v4_clean_curl_options_rejected');
        }
        if ($method !== 'GET') {
            if (!curl_setopt($ch, CURLOPT_POSTFIELDS, $body)) {
                throw new RuntimeException('queue_v4_clean_curl_body_option_rejected');
            }
        }

        $lastHeartbeat=0.0;
        if (!curl_setopt($ch,CURLOPT_NOPROGRESS,false)
            || !curl_setopt($ch,CURLOPT_XFERINFOFUNCTION,static function()use(&$lastHeartbeat,$executionSource):int{
                if (CronDeadlineContext::remainingSeconds() <= 0.0) { return 1; }
                $budgetDeadline = \App\QueueV4Clean\QueueV4CleanCycleBudget::snapshot()['deadline'];
                if ($budgetDeadline !== null && microtime(true) >= $budgetDeadline) { return 1; }
                if ($executionSource !== 'queue_core') { return 0; }
                $now=microtime(true);
                if($now-$lastHeartbeat<1.0)return 0;
                $lastHeartbeat=$now;
                try { return \App\QueueCore\QueueCoreDispatchFence::heartbeat()?0:1; }
                catch (\Throwable) { return 1; }
            })) {
            throw new RuntimeException('queue_v4_clean_curl_progress_option_rejected');
        }
        $prepared = false;
        $requestId = (string) (ApiExecutionMetadataContext::current()['transport_request_id'] ?? '');
        try {
        \App\QueueV4Clean\QueueV4CleanCycleBudget::reserve($requestId, $executionSource);
        if($executionSource==='queue_core'){
            // Persist the physical boundary only after cURL is fully prepared
            // and immediately before curl_exec.
            $emergency->assertTransportAllowed($method, $url);
            \App\QueueCore\QueueCoreDispatchFence::transportStarted(
                $method,
                parse_url($url,PHP_URL_PATH)?:'/'
            );
            \App\QueueCore\QueueCoreDispatchFence::immediatelyBeforeCurl(
                $method,
                parse_url($url,PHP_URL_PATH)?:'/'
            );
        }
        if (MeliTransportSourcePolicy::requiresCurrentOAuthFence($executionSource)) {
            $emergency->assertTransportAllowed($method, $url);
            \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
                \App\QueueV4Clean\QueueV4CleanOAuthStageContext::OAUTH_DISPATCH_FENCE
            );
            \App\QueueV4Clean\QueueV4CleanOAuthDispatchFence::immediatelyBeforeCurl(
                $method,
                parse_url($url, PHP_URL_PATH) ?: '/'
            );
            $prepared = true;
        }
        if (MeliTransportSourcePolicy::requiresQueueV4ReadFence($executionSource)) {
            $emergency->assertTransportAllowed($method, $url);
            \App\QueueV4Clean\QueueV4CleanDispatchFence::immediatelyBeforeCurl(
                $method,
                parse_url($url, PHP_URL_PATH) ?: '/'
            );
            $prepared = true;
        }
        // Fence/DB setup can be slow. Recalculate at the physical boundary,
        // preserving any shorter timeout supplied by the caller.
        $budgetDeadline = \App\QueueV4Clean\QueueV4CleanCycleBudget::snapshot()['deadline'];
        CronDeadlineContext::assertCanStartRemote(1.0, $budgetDeadline);
        $freshTimeouts = CronDeadlineContext::curlTimeouts();
        if ($budgetDeadline !== null) {
            $remaining = max(1, (int) floor($budgetDeadline - microtime(true)));
            $freshTimeouts['timeout'] = min($freshTimeouts['timeout'], $remaining);
            $freshTimeouts['connect_timeout'] = min($freshTimeouts['connect_timeout'], $remaining);
        }
        if (!curl_setopt_array($ch, [
            CURLOPT_TIMEOUT => max(1, min($timeouts['timeout'], $freshTimeouts['timeout'])),
            CURLOPT_CONNECTTIMEOUT => max(1, min($timeouts['connect_timeout'], $freshTimeouts['connect_timeout'])),
        ])) { throw new RuntimeException('queue_v4_clean_curl_final_timeout_rejected'); }
        \App\QueueV4Clean\QueueV4CleanCycleBudget::enteringTransport($requestId);
        } catch (\Throwable $blocked) {
            if ($blocked instanceof RemoteResultUncertainException) {
                \App\QueueV4Clean\QueueV4CleanCycleBudget::stop('remote_result_uncertain');
                throw $blocked;
            }
            if ($prepared) {
                try {
                    $cancelled = \App\QueueV4Clean\QueueV4CleanTransportJournal::cancelBeforeCurl(
                        \App\Core\Database::connectionFresh(), ApiExecutionMetadataContext::current()
                    );
                } catch (\Throwable) { $cancelled = false; }
                if (!$cancelled) { throw new RemoteResultUncertainException((string)(ApiExecutionMetadataContext::current()['transport_request_id']??'')); }
            } elseif ($executionSource === 'queue_core' && \App\QueueCore\QueueCoreDispatchFence::physicalTransportRecorded()) {
                if (!\App\QueueCore\QueueCoreDispatchFence::cancelBeforeCurl()) {
                    throw new RemoteResultUncertainException((string)(ApiExecutionMetadataContext::current()['transport_request_id']??''));
                }
            }
            \App\QueueV4Clean\QueueV4CleanCycleBudget::releaseBeforeTransport($requestId);
            throw $blocked;
        }
        $started = microtime(true);
        \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
            \App\QueueV4Clean\QueueV4CleanOAuthStageContext::CURL_EXEC
        );
        \App\QueueV4Clean\QueueV4CleanTransportJournal::enteringCurl((string)(ApiExecutionMetadataContext::current()['transport_request_id']??''));
        \App\QueueCore\QueueCoreDispatchFence::enteringCurl();
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $wireBytes = defined('CURLINFO_SIZE_DOWNLOAD_T')
            ? (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD_T)
            : (is_string($raw) ? strlen($raw) : 0);
        $curlError = curl_error($ch);
        unset($ch);
        if (MeliTransportSourcePolicy::requiresCurrentOAuthFence($executionSource)
            && $status > 0 && $curlError === '') {
            \App\QueueV4Clean\QueueV4CleanOAuthStageContext::setForCurrentOAuth(
                \App\QueueV4Clean\QueueV4CleanOAuthStageContext::RESPONSE_KNOWN
            );
            \App\QueueV4Clean\QueueV4CleanOAuthDispatchFence::responseKnown($status);
        }
        if (MeliTransportSourcePolicy::requiresQueueV4ReadFence($executionSource)
            && $status > 0 && $curlError === '') {
            \App\QueueV4Clean\QueueV4CleanDispatchFence::responseKnown($status);
        }
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if ($emergencySource === 'manual_emergency_canary') {
            (new EmergencyControlService())->completeCanaryTransport(
                $curlError === '' && $status >= 200 && $status < 300,
                $status > 0 ? $status : null
            );
        }

        // OAuth es distinto al canario de lectura: una respuesta 2xx puede
        // rotar el refresh token remoto. No debe existir ninguna escritura
        // fallible de control-plane antes de devolver ese body al servicio que
        // valida y persiste ambos tokens transaccionalmente. El estado OAuth se
        // completa únicamente después de confirmar la persistencia.

        return [
            'status' => $status,
            'body' => is_array($decoded) ? $decoded : [],
            'headers' => $responseHeaders,
            'curl_error' => $curlError,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'wire_bytes' => max(0, $wireBytes),
            'decoded_bytes' => is_string($raw) ? strlen($raw) : 0,
        ];
    }
}
