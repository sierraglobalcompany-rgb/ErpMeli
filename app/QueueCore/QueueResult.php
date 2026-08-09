<?php
declare(strict_types=1);
namespace App\QueueCore;
final readonly class QueueResult
{
    private function __construct(public string $outcome, public int $resourcesDiscovered=0,
        public int $resourcesPersisted=0, public ?string $errorClass=null,
        public ?int $httpStatus=null, public ?string $retryAt=null) {}
    public static function completed(int $persisted=0,int $discovered=0): self { return new self('completed',max(0,$discovered),max(0,$persisted)); }
    public static function retry(string $errorClass,?string $retryAt=null,?int $httpStatus=null): self { return new self('retry_wait',0,0,self::safeClass($errorClass),$httpStatus,$retryAt); }
    public static function review(string $errorClass,?int $httpStatus=null): self { return new self('review',0,0,self::safeClass($errorClass),$httpStatus); }
    public static function dead(string $errorClass,?int $httpStatus=null): self { return new self('dead',0,0,self::safeClass($errorClass),$httpStatus); }
    private static function safeClass(string $value): string { $safe=strtolower((string)preg_replace('/[^a-z0-9_]+/i','_',$value)); return substr(trim($safe,'_'),0,100)?:'unknown_failure'; }
}
