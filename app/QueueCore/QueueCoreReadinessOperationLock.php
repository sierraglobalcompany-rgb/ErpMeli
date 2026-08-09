<?php

declare(strict_types=1);

namespace App\QueueCore;

use PDO;
use RuntimeException;

/** Serializa una medición completa de readiness contra el CAS de activación. */
final class QueueCoreReadinessOperationLock
{
    private const LOCK_NAME = 'erp_meli_queue_readiness_authority';

    /** @template T @param callable():T $operation @return T */
    public static function with(PDO $pdo, callable $operation): mixed
    {
        $claim=$pdo->prepare('SELECT GET_LOCK(?,0)');
        $claim->execute([self::LOCK_NAME]);
        if((int)$claim->fetchColumn()!==1){
            throw new RuntimeException('Queue Core readiness authority is busy.');
        }
        try{return $operation();}
        finally{
            $release=$pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([self::LOCK_NAME]);
        }
    }
}
