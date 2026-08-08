<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AuthenticationAttemptService
{
    public function isLimited(string $account, string $ip): bool
    {
        $pdo = Database::connection();
        $accountHash = hash('sha256', strtolower(trim($account)));
        $ipHash = hash('sha256', trim($ip));
        $statement = $pdo->prepare(
            "SELECT
                (SELECT COUNT(*) FROM login_attempts
                 WHERE email_hash=:account AND succeeded=0
                   AND attempted_at>=DATE_SUB(NOW(), INTERVAL 15 MINUTE)) account_failures,
                (SELECT COUNT(*) FROM login_attempts
                 WHERE ip_hash=:ip AND succeeded=0
                   AND attempted_at>=DATE_SUB(NOW(), INTERVAL 15 MINUTE)) ip_failures"
        );
        $statement->execute(['account' => $accountHash, 'ip' => $ipHash]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC) ?: [];
        return (int) ($row['account_failures'] ?? 0) >= 5
            || (int) ($row['ip_failures'] ?? 0) >= 20;
    }

    public function record(string $account, string $ip, bool $success): void
    {
        $statement = Database::connection()->prepare(
            'INSERT INTO login_attempts (email_hash,ip_hash,succeeded) VALUES (:account,:ip,:success)'
        );
        $statement->execute([
            'account' => hash('sha256', strtolower(trim($account))),
            'ip' => hash('sha256', trim($ip)),
            'success' => $success ? 1 : 0,
        ]);
    }
}
