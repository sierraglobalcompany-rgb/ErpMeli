<?php
declare(strict_types=1);
require __DIR__.'/k1b_bootstrap.php';
$newFixture = is_file(__DIR__.'/calls_true_wire_fixture.php');
require $newFixture ? __DIR__.'/calls_true_wire_fixture.php' : __DIR__.'/calls_transport_wire_fixture.php';
$ch=curl_init('https://calls-wire.invalid/unexpected');
try { App\Services\curl_exec($ch); } catch (RuntimeException) {}
$observed=$newFixture ? App\Services\CallsTrueWire::$entries : App\Services\Cap2DomainsWire::$calls;
k1b_assert(count($observed)===1, 'WIRE_ENTRY_MUST_BE_RECORDED_BEFORE_FIXTURE_LOOKUP');
if (!$newFixture) { throw new RuntimeException('WIRE_FAILURE_MODEL_MISSING'); }
k1b_assert(property_exists(App\Services\CallsTrueWire::class,'violations')&&count(App\Services\CallsTrueWire::$violations)===1,'SWALLOWED_FIXTURE_VIOLATION_REMAINS_OBSERVABLE');
App\Services\CallsTrueWire::$respond=static fn(array $entry):array=>['status'=>0,'raw'=>false,'error'=>'Operation timed out','errno'=>CURLE_OPERATION_TIMEDOUT];
k1b_assert(App\Services\curl_exec($ch)===false,'WIRE_TIMEOUT_RETURNS_FALSE');
k1b_assert(App\Services\curl_error($ch)==='Operation timed out','WIRE_TIMEOUT_ERROR_PRESERVED');
k1b_assert(App\Services\curl_getinfo($ch,CURLINFO_HTTP_CODE)===0,'WIRE_TIMEOUT_HAS_NO_INVENTED_HTTP_STATUS');
App\Services\CallsTrueWire::$respond=static fn(array $entry):array=>['status'=>200,'raw'=>false,'error'=>'transfer closed with outstanding data','errno'=>CURLE_PARTIAL_FILE,'wire_bytes'=>12];
k1b_assert(App\Services\curl_exec($ch)===false,'WIRE_PARTIAL_RETURNS_FALSE');
k1b_assert(App\Services\curl_getinfo($ch,CURLINFO_HTTP_CODE)===200,'WIRE_PARTIAL_PRESERVES_KNOWN_STATUS');
k1b_assert(count(App\Services\CallsTrueWire::$entries)===3,'WIRE_EACH_EXECUTION_COUNTS_ONCE');
echo "PASS TRUE_WIRE_CONTRACT REAL_MELI_HTTP=0\n";

