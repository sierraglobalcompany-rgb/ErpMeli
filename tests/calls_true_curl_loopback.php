<?php
declare(strict_types=1);
// Calibrates the fixture's return/error/status model with real cURL, loopback only.
if(PHP_SAPI==='cli-server'){
    if(!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){http_response_code(403);exit;}
    $path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
    if($path==='/timeout'){sleep(2);echo 'late';exit;}
    if($path==='/partial'){header('Content-Length: 100');echo 'abc';exit;}
    echo 'ready';exit;
}
require __DIR__.'/k1b_bootstrap.php';
$root=(string)(getenv('CALLS_TRUE_QA_ROOT')?:sys_get_temp_dir());
$socket=stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
k1b_assert(is_resource($socket),'LOOPBACK_SOCKET');
$address=stream_socket_get_name($socket,false);fclose($socket);
$p=proc_open([PHP_BINARY,'-S',$address,__FILE__],[0=>['pipe','r'],1=>['file',$root.'/loopback-server.out.log','a'],2=>['file',$root.'/loopback-server.err.log','a']],$pipes,__DIR__);
k1b_assert(is_resource($p),'LOOPBACK_SERVER_STARTED');fclose($pipes[0]);
$observed=[];
try{
    $deadline=microtime(true)+5;$ready=false;
    while(microtime(true)<$deadline){$probe=@stream_socket_client('tcp://'.$address,$errno,$error,0.1);if($probe){fclose($probe);$ready=true;break;}usleep(20000);}
    k1b_assert($ready,'LOOPBACK_READY');
    foreach(['timeout'=>[0,CURLE_OPERATION_TIMEDOUT],'partial'=>[200,CURLE_PARTIAL_FILE]] as $path=>[$status,$errno]){
        $ch=curl_init('http://'.$address.'/'.$path);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT_MS=>$path==='timeout'?250:4000,CURLOPT_FOLLOWLOCATION=>false]);
        $entered=microtime(true);$raw=curl_exec($ch);
        $row=['path'=>$path,'entered'=>$entered,'false'=>$raw===false,'status'=>curl_getinfo($ch,CURLINFO_HTTP_CODE),'errno'=>curl_errno($ch),'bytes'=>curl_getinfo($ch,CURLINFO_SIZE_DOWNLOAD_T)];
        $observed[]=$row;
        k1b_assert($row['false']&&$row['status']===$status&&$row['errno']===$errno,'REAL_CURL_MODEL_'.$path.':'.json_encode($row));
    }
    file_put_contents($root.'/loopback-observations.json',json_encode($observed,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo "PASS REAL_CURL_LOOPBACK_MODEL EXTERNAL_HTTP=0\n";
}finally{proc_terminate($p);proc_close($p);}

