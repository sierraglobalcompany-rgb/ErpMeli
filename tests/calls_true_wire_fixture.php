<?php
declare(strict_types=1);
namespace App\Services;

/** Independent final cURL boundary. No counter/journal is used to count observations. */
final class CallsTrueWire
{
    public static array $entries=[];
    public static array $violations=[];
    public static ?\Closure $respond=null;
    public static array $options=[];
    public static array $response=[];
    public static ?string $ledger=null;
    public static int $execution=0;
}

function curl_setopt_array(\CurlHandle $handle,array $options):bool
{
    CallsTrueWire::$options[spl_object_id($handle)]=array_replace(CallsTrueWire::$options[spl_object_id($handle)]??[],$options);
    return \curl_setopt_array($handle,$options);
}

function curl_exec(\CurlHandle $handle):string|false
{
    $options=CallsTrueWire::$options[spl_object_id($handle)]??[];
    $url=(string)\curl_getinfo($handle,CURLINFO_EFFECTIVE_URL);
    parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    $meta=ApiExecutionMetadataContext::current();
    $entry=[
        'sequence'=>count(CallsTrueWire::$entries)+1,'execution'=>CallsTrueWire::$execution,
        'pid'=>getmypid(),'entered_at'=>microtime(true),
        'method'=>(string)($options[CURLOPT_CUSTOMREQUEST]??'GET'),
        'path'=>(string)parse_url($url,PHP_URL_PATH),
        'order_ids'=>$query['order_ids']??null,
        'safe_query'=>array_intersect_key($query,array_flip(['seller','order_date_created_from','order_date_created_to','offset','limit'])),
        'transport_request_id'=>(string)($meta['transport_request_id']??''),
        'source'=>(string)($meta['source']??''),
        'company_id'=>(int)($meta['company_id']??0),'account_id'=>(int)($meta['account_id']??0),
    ];
    CallsTrueWire::$entries[]=$entry;
    if(CallsTrueWire::$ledger!==null)file_put_contents(CallsTrueWire::$ledger,json_encode($entry,JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
    // Observe first, then reject unexpected requests. Never fall through to real network.
    CallsTrueWire::$response=[];
    try{
        if(parse_url($url,PHP_URL_HOST)!=='calls-wire.invalid'||CallsTrueWire::$respond===null)throw new \RuntimeException('UNEXPECTED_WIRE_REQUEST');
        CallsTrueWire::$response=(CallsTrueWire::$respond)($entry);
    }catch(\Throwable $error){
        CallsTrueWire::$violations[]=['sequence'=>$entry['sequence'],'kind'=>'FIXTURE_REQUEST_REJECTED','exception_type'=>get_class($error)];
        throw $error;
    }
    if(!array_key_exists('raw',CallsTrueWire::$response))CallsTrueWire::$response['raw']=json_encode(CallsTrueWire::$response['body']??[],JSON_THROW_ON_ERROR);
    $r=CallsTrueWire::$response;
    if(isset($options[CURLOPT_HEADERFUNCTION])&&($r['status']??0)>0){
        $headers=['HTTP/1.1 '.$r['status']." Fixture\r\n"];
        foreach(($r['headers']??[])as $name=>$value)$headers[]=$name.': '.$value."\r\n";
        foreach($headers as $line)($options[CURLOPT_HEADERFUNCTION])($handle,$line);
    }
    return $r['raw']??json_encode($r['body']??[],JSON_THROW_ON_ERROR);
}

function curl_getinfo(\CurlHandle $handle,?int $option=null):mixed
{
    return match($option){
        CURLINFO_HTTP_CODE=>(int)(CallsTrueWire::$response['status']??0),
        CURLINFO_SIZE_DOWNLOAD_T=>(int)(CallsTrueWire::$response['wire_bytes']??strlen((string)(CallsTrueWire::$response['raw']??''))),
        default=>$option===null?\curl_getinfo($handle):\curl_getinfo($handle,$option),
    };
}
function curl_error(\CurlHandle $handle):string{return(string)(CallsTrueWire::$response['error']??'');}
function curl_errno(\CurlHandle $handle):int{return(int)(CallsTrueWire::$response['errno']??0);}
