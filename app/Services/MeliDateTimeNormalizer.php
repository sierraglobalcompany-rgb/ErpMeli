<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class MeliDateTimeNormalizer
{
    public const VERSION = '2.5.0';

    /**
     * @return array{
     *   raw_value:?string,source_offset:?string,utc:?string,local:?string,local_date:?string,
     *   erp_timezone:string,source_field:string,status:string,error:?string
     * }
     */
    public function normalize(mixed $value, string $sourceField = ''): array
    {
        $raw = $value === null || $value === '' ? null : (string) $value;
        $timezone = DateTimePresenter::timezone();
        $empty = [
            'raw_value' => $raw,
            'source_offset' => null,
            'utc' => null,
            'local' => null,
            'local_date' => null,
            'erp_timezone' => $timezone,
            'source_field' => $sourceField,
            'status' => $raw === null ? 'empty' : 'error',
            'error' => null,
        ];
        if ($raw === null) {
            return $empty;
        }

        try {
            $date = new DateTimeImmutable($raw);
            $utc = $date->setTimezone(new DateTimeZone('UTC'));
            $local = $utc->setTimezone(new DateTimeZone($timezone));
            return [
                'raw_value' => $raw,
                'source_offset' => $this->sourceOffset($raw),
                'utc' => $utc->format('Y-m-d H:i:s'),
                'local' => $local->format('Y-m-d H:i:s'),
                'local_date' => $local->format('Y-m-d'),
                'erp_timezone' => $timezone,
                'source_field' => $sourceField,
                'status' => 'ok',
                'error' => null,
            ];
        } catch (Throwable $e) {
            $empty['error'] = mb_substr($e->getMessage(), 0, 255);
            return $empty;
        }
    }

    public function utc(mixed $value, string $sourceField = ''): ?string
    {
        $normalized = $this->normalize($value, $sourceField);
        return $normalized['status'] === 'ok' ? $normalized['utc'] : null;
    }

    private function sourceOffset(string $raw): ?string
    {
        if (preg_match('/Z$/i', $raw) === 1) {
            return 'Z';
        }
        if (preg_match('/([+-]\d{2}:?\d{2})$/', $raw, $matches) === 1) {
            $offset = $matches[1];
            return strlen($offset) === 5 ? substr($offset, 0, 3) . ':' . substr($offset, 3, 2) : $offset;
        }
        return null;
    }
}
