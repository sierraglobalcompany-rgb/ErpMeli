<?php
declare(strict_types=1);
namespace App\Services;
require __DIR__ . '/cap2_domains_wire_fixture.php';
require __DIR__ . '/cap2_transport_clock_fixture.php';

final class Cap2TransportOptions
{
    public static array $values=[];
}
function curl_setopt_array(\CurlHandle $ch, array $options): bool
{
    Cap2TransportOptions::$values = array_replace(Cap2TransportOptions::$values,$options);
    return \curl_setopt_array($ch,$options);
}
function curl_setopt(\CurlHandle $ch, int $option, mixed $value): bool
{
    Cap2TransportOptions::$values[$option]=$value;
    return \curl_setopt($ch,$option,$value);
}
