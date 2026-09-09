<?php
declare(strict_types=1);
/** Fault only the journal authority boundary; all other SQL remains real PDO MySQL. */
final class Cap2TransportMetricPdo extends PDO
{
    public function prepare(string $query,array $options=[]): PDOStatement|false
    {
        if(str_contains($query,'information_schema') || str_contains($query,'queue_v4_clean_transport_events')) {
            throw new PDOException('test_journal_authority_unavailable');
        }
        return parent::prepare($query,$options);
    }
}

/** Lose the commit acknowledgement after a real MySQL commit, once only. */
final class Cap2TransportCommitPdo extends PDO
{
    public bool $armed=true;
    public bool $uncertifiable=false;
    public function commit(): bool
    {
        $result=parent::commit();
        if($this->armed) {
            $request=(string)(App\Services\ApiExecutionMetadataContext::current()['transport_request_id']??'');
            $query=$this->prepare('SELECT COUNT(*) FROM queue_v4_clean_transport_events WHERE request_id=?');$query->execute([$request]);
            if((int)$query->fetchColumn()>0) {
                $this->armed=false;
                if($this->uncertifiable)$this->prepare("UPDATE queue_v4_clean_transport_events SET dispatch_state='RESPONSE_KNOWN',http_status=200 WHERE request_id=?")->execute([$request]);
                throw new PDOException('test_commit_acknowledgement_lost');
            }
        }
        return $result;
    }
}

/** INSERT succeeds but its acknowledgement and the subsequent rollback both fail. */
final class Cap2TransportInsertPdo extends PDO
{
    public bool $failRollback=true;
    public bool $rollbackBeforeFailure=false;
    public function rollBack(): bool
    {
        if($this->failRollback && $this->rollbackBeforeFailure) parent::rollBack();
        if($this->failRollback) throw new PDOException('test_rollback_acknowledgement_lost');
        return parent::rollBack();
    }
}
final class Cap2TransportInsertStatement extends PDOStatement
{
    protected function __construct() {}
    public function execute(?array $params=null): bool
    {
        $result=parent::execute($params);
        if(str_contains($this->queryString,'INSERT INTO queue_v4_clean_transport_events')) throw new PDOException('test_insert_acknowledgement_lost');
        return $result;
    }
}
