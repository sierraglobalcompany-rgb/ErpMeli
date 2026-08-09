<?php
declare(strict_types=1);
namespace App\QueueCore;
final class QueueRetryPolicy
{
    public static function nextAttemptAt(int $attemptCount,?int $retryAfterSeconds=null): string
    {
        if($retryAfterSeconds!==null){$seconds=max(1,min(86400,$retryAfterSeconds));}
        else{$exponent=max(0,min(10,$attemptCount-1));$seconds=min(3600,5*(2**$exponent));$seconds+=($attemptCount*17)%max(1,(int)ceil($seconds/5));}
        return gmdate('Y-m-d H:i:s',time()+$seconds);
    }
}
