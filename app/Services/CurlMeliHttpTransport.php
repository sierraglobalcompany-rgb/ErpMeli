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
        // Última barrera independiente del llamador: nunca abrir cURL hacia
        // Mercado Libre mientras exista la parada local de emergencia.
        $emergency = new MeliEmergencyStopService();
        $method = strtoupper($method);
        $executionSource=(string)(ApiExecutionMetadataContext::current()['source']??'');
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
        if (in_array($emergencySource, ['queue_core', 'queue_v4_clean', 'queue_v4_clean_readiness', 'manual_emergency_canary', 'manual_emergency_oauth_refresh'], true)) {
            // Un redirect también sería otra solicitud física. El canario no
            // puede seguirlo, ni siquiera cuando el servidor responda 301/302.
            $options[CURLOPT_FOLLOWLOCATION] = false;
            $options[CURLOPT_MAXREDIRS] = 0;
        }
        curl_setopt_array($ch, $options);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        if($executionSource==='queue_core'){
            $lastHeartbeat=0.0;
            curl_setopt($ch,CURLOPT_NOPROGRESS,false);
            curl_setopt($ch,CURLOPT_XFERINFOFUNCTION,static function()use(&$lastHeartbeat):int{
                $now=microtime(true);
                if($now-$lastHeartbeat<1.0)return 0;
                $lastHeartbeat=$now;
                return \App\QueueCore\QueueCoreDispatchFence::heartbeat()?0:1;
            });
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
        $started = microtime(true);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $wireBytes = defined('CURLINFO_SIZE_DOWNLOAD_T')
            ? (int) curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD_T)
            : (is_string($raw) ? strlen($raw) : 0);
        $curlError = curl_error($ch);
        unset($ch);
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
