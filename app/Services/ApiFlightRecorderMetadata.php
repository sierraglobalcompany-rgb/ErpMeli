<?php

declare(strict_types=1);

namespace App\Services;

final class ApiFlightRecorderMetadata
{
    /** Accept only an already-existing, non-secret lifecycle identifier. */
    public static function normalizeTraceId(mixed $traceId): ?string
    {
        if (!is_string($traceId)) {
            return null;
        }
        $traceId = trim($traceId);
        return $traceId !== '' && strlen($traceId) <= 64
            && preg_match('/^[A-Za-z0-9._:-]+$/D', $traceId) === 1
            ? $traceId
            : null;
    }

    /** Serialize only bounded, allowlisted response headers. */
    public static function safeResponseHeaders(array $headers): ?string
    {
        $limits = [
            'retry-after' => 64,
            'x-ratelimit-limit' => 64,
            'x-ratelimit-remaining' => 64,
            'x-ratelimit-reset' => 64,
            'cf-ray' => 128,
            'date' => 128,
            'x-request-id' => 128,
            'x-correlation-id' => 128,
        ];
        $observed = [];
        foreach ($headers as $name => $value) {
            $key = strtolower(trim((string) $name));
            if (!isset($limits[$key]) || !is_string($value)) {
                continue;
            }
            $safe = preg_replace('/[\x00-\x1F\x7F]/', '', trim($value));
            if (!is_string($safe)) {
                continue;
            }
            $observed[$key] = substr($safe, 0, $limits[$key]);
        }
        if ($observed === []) {
            return null;
        }
        $ordered = [];
        foreach ($limits as $key => $_limit) {
            if (array_key_exists($key, $observed)) {
                $ordered[$key] = $observed[$key];
            }
        }
        $json = json_encode($ordered, JSON_UNESCAPED_SLASHES);
        return is_string($json) && strlen($json) <= 2048 ? $json : null;
    }

    /** A local process timestamp immediately before cURL, not remote receipt. */
    public static function physicalStartUtcMilliseconds(?float $timestamp): ?string
    {
        if ($timestamp === null || !is_finite($timestamp)) {
            return null;
        }
        $seconds = (int) floor($timestamp);
        $milliseconds = (int) floor(($timestamp - $seconds) * 1000);
        return gmdate('Y-m-d H:i:s', $seconds) . '.' . str_pad((string) max(0, min(999, $milliseconds)), 3, '0', STR_PAD_LEFT);
    }

    public static function normalizePhysicalStart(mixed $value): ?string
    {
        if (!is_string($value)
            || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{3}$/D', $value) !== 1) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.v', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            return null;
        }
        return $date->format('Y-m-d H:i:s.v');
    }

    public static function safeResponseHeadersJson(mixed $value): ?string
    {
        if (!is_string($value) || strlen($value) > 2048) {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? self::safeResponseHeaders($decoded) : null;
    }
}
