<?php

declare(strict_types=1);

namespace App\Services;

interface MeliHttpTransportInterface
{
    /**
     * @param list<string> $headers
     * @param array{timeout:int,connect_timeout:int} $timeouts
     * @return array{status:int,body:array<string,mixed>,headers:array<string,string>,curl_error:string,duration_ms:int,wire_bytes:int,decoded_bytes:int}
     */
    public function request(
        string $method,
        string $url,
        array $data,
        array $headers,
        bool $form,
        array $timeouts
    ): array;
}
