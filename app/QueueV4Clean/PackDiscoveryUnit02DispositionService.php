<?php

declare(strict_types=1);

namespace App\QueueV4Clean;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Private application boundary for the single K3 UNIT-02 disposition.
 *
 * It is deliberately not wired to HTTP, CLI, Cron or the worker. A future
 * authorized operator may call it explicitly after supplying the preserved
 * evidence. Installing this class never creates or renews the disposition.
 */
final class PackDiscoveryUnit02DispositionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $authorizedEvidence
     * @return array{status:string,authority_sha256:string,pack_integrity_sha256:string}
     */
    public function apply(
        array $authorizedEvidence,
        int $actorUserId,
        string $reason,
        bool $ownerApproved,
    ): array {
        if (!$ownerApproved || $actorUserId < 1) {
            throw new RuntimeException('k3_unit02_owner_approval_required');
        }
        if ($this->pdo->inTransaction()) {
            throw new RuntimeException('k3_unit02_outer_transaction_not_allowed');
        }

        $this->pdo->beginTransaction();
        try {
            $this->assertPermanentAdmin($actorUserId);
            $policy = new PackDiscoveryOccupancyPolicy($this->pdo);
            $policy->lockAdmissionAuthority();
            $existing = $policy->storedUnit02Disposition();
            if (is_array($existing)) {
                if (!$policy->dispositionRepresentsEvidence($existing, $authorizedEvidence, $actorUserId, $reason)) {
                    throw new RuntimeException('k3_unit02_existing_authority_conflict');
                }
                $json = $policy->canonicalDispositionJson($existing);
                $this->pdo->commit();

                return [
                    'status' => 'ALREADY_APPLIED',
                    'authority_sha256' => hash('sha256', $json),
                    'pack_integrity_sha256' => (string) $existing['hashes']['pack_integrity_sha256'],
                ];
            }
            if ($this->settingRowExists()) {
                throw new RuntimeException('k3_unit02_existing_authority_conflict');
            }

            $document = $policy->prepareUnit02DispositionAuthority(
                $authorizedEvidence,
                $actorUserId,
                gmdate('Y-m-d\TH:i:s\Z'),
                $reason,
            );
            $json = $policy->canonicalDispositionJson($document);
            $insert = $this->pdo->prepare(
                'INSERT INTO app_settings(setting_key,setting_value,is_encrypted,setting_group) VALUES(?,?,0,?)'
            );
            $insert->execute([
                PackDiscoveryOccupancyPolicy::UNIT02_DISPOSITION_KEY,
                $json,
                'queue_v4_clean',
            ]);
            if ($insert->rowCount() !== 1) {
                throw new RuntimeException('k3_unit02_authority_insert_failed');
            }
            $audit = $this->pdo->prepare(
                'INSERT INTO audit_logs
                    (user_id,action,module,entity_type,entity_id,meli_account_id,ip_hash,before_json,after_json)
                 VALUES (?,?,?,?,?,?,?,?,?)'
            );
            $audit->execute([
                $actorUserId,
                'pack_discovery_unit02_disposition_applied',
                'queue_v4_clean',
                'queue_v4_clean_job',
                (int) $document['identity']['queue_id'],
                (int) $document['identity']['meli_account_id'],
                hash('sha256', 'cli'),
                null,
                json_encode([
                    'setting_key' => PackDiscoveryOccupancyPolicy::UNIT02_DISPOSITION_KEY,
                    'status' => PackDiscoveryOccupancyPolicy::UNIT02_DISPOSITION_STATUS,
                    'authority_sha256' => hash('sha256', $json),
                    'non_renewable' => true,
                    'max_units' => 1,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]);
            if ($audit->rowCount() !== 1) {
                throw new RuntimeException('k3_unit02_audit_insert_failed');
            }
            $this->pdo->commit();

            return [
                'status' => 'APPLIED',
                'authority_sha256' => hash('sha256', $json),
                'pack_integrity_sha256' => (string) $document['hashes']['pack_integrity_sha256'],
            ];
        } catch (Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }

    private function settingRowExists(): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM app_settings WHERE setting_key=? LIMIT 1 FOR UPDATE');
        $statement->execute([PackDiscoveryOccupancyPolicy::UNIT02_DISPOSITION_KEY]);

        return $statement->fetchColumn() !== false;
    }

    private function assertPermanentAdmin(int $actorUserId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT role,status,is_temporary FROM users WHERE id=? LIMIT 1 FOR UPDATE"
        );
        $statement->execute([$actorUserId]);
        $actor = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($actor)
            || (string) ($actor['role'] ?? '') !== 'admin'
            || (int) ($actor['status'] ?? 0) !== 1
            || (int) ($actor['is_temporary'] ?? 1) !== 0) {
            throw new RuntimeException('k3_unit02_actor_not_permanent_admin');
        }
    }
}
