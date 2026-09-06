<?php
declare(strict_types=1);
namespace App\Services;
require __DIR__.'/cap2_domains_wire_fixture.php';
final class CallsWireOptions {public static array $headers=[];public static ?\Closure $onFinalOptions=null;public static float $clockOffset=0.0;}
function microtime(bool $asFloat=false):float|string {return $asFloat?\microtime(true)+CallsWireOptions::$clockOffset:\microtime();}
function curl_setopt_array(\CurlHandle $handle,array $options):bool {
    if(isset($options[CURLOPT_HTTPHEADER]))CallsWireOptions::$headers=$options[CURLOPT_HTTPHEADER];
    $result=\curl_setopt_array($handle,$options);
    if(!isset($options[CURLOPT_HTTPHEADER])&&isset($options[CURLOPT_TIMEOUT])&&CallsWireOptions::$onFinalOptions!==null)(CallsWireOptions::$onFinalOptions)();
    return $result;
}
