<?php

declare(strict_types=1);

use App\Core\Database;

final class K1dSafeTestDatabase
{
    public readonly string $dbName;
    public readonly string $host;
    public readonly string $port;

    private PDO $admin;
    private bool $created = false;
    private string $ownershipJournal = '';
    private string $creationAttempt = '';

    private function __construct()
    {
        $this->host = (string) getenv('DB_HOST');
        $this->port = (string) (getenv('DB_PORT') ?: '3306');
        $this->dbName = (string) getenv('DB_NAME');
        $this->assertGuard((string) getenv('APP_ENV'), (string) getenv('ML_WRITE_ENABLED'), $this->host, $this->dbName);

        $this->admin = $this->openAdminConnection();
    }

    public static function assertGuard(string $appEnv, string $writeEnabled, string $host, string $dbName): void
    {
        $host = strtolower(trim($host));
        if ($appEnv !== 'test') {
            throw new RuntimeException('TEST_DB_GUARD=FAIL reason=APP_ENV_NOT_TEST DESTRUCTIVE_SQL_EXECUTED=NO');
        }
        if (!in_array(strtolower($writeEnabled), ['false', '0', 'no'], true)) {
            throw new RuntimeException('TEST_DB_GUARD=FAIL reason=ML_WRITE_ENABLED_NOT_FALSE DESTRUCTIVE_SQL_EXECUTED=NO');
        }
        if (!in_array($host, ['localhost', '127.0.0.1'], true)) {
            throw new RuntimeException('TEST_DB_GUARD=FAIL reason=DB_HOST_NOT_LOCAL DESTRUCTIVE_SQL_EXECUTED=NO');
        }
        if (preg_match('/^erp_meli_k1d_test_[a-z0-9_]+$/', $dbName) !== 1) {
            throw new RuntimeException('TEST_DB_GUARD=FAIL reason=DB_NAME_NOT_EPHEMERAL DESTRUCTIVE_SQL_EXECUTED=NO');
        }
    }

    public static function createFromEnvironment(): self
    {
        $instance = new self();
        $instance->createDatabase();
        return $instance;
    }

    public static function connectExistingFromEnvironment(): self
    {
        return new self();
    }

    public function pdo(): PDO
    {
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $this->host, $this->port, $this->dbName),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASS'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ]
        );
        $pdo->exec('SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci');
        $pdo->exec("SET SESSION time_zone='+00:00'");
        Database::useProfile('migration');
        Database::setConnection($pdo);
        return $pdo;
    }

    /** Keep this instance's disposable-database admin connection alive between long migration batches. */
    public function keepAdminAlive(): void
    {
        self::assertGuard((string) getenv('APP_ENV'), (string) getenv('ML_WRITE_ENABLED'), $this->host, $this->dbName);
        try {
            $this->admin->query('SELECT 1')->fetchColumn();
        } catch (PDOException $exception) {
            if (!in_array((int) ($exception->errorInfo[1] ?? 0), [2006, 2013], true)) {
                throw $exception;
            }
            $this->admin = $this->openAdminConnection();
            $this->admin->query('SELECT 1')->fetchColumn();
        }
    }

    public function cleanup(): void
    {
        if (!$this->created) {
            return;
        }
        self::assertGuard((string) getenv('APP_ENV'), (string) getenv('ML_WRITE_ENABLED'), $this->host, $this->dbName);
        $this->keepAdminAlive();
        $this->admin->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
        $this->created = false;
        $this->recordOwnershipEvent('dropped');
    }

    private function createDatabase(): void
    {
        $root = rtrim(str_replace('\\', '/', (string) (getenv('CALLS_VERIFY_QA_ROOT')
            ?: 'D:/Codex/tmp/erp-meli/calls-20260906/database-ownership')), '/');
        $projectStorage = rtrim(str_replace('\\', '/', dirname(__DIR__) . '/storage/codex-'), '/');
        if (!(str_starts_with($root, 'D:/Codex/')
                || str_starts_with($root, 'C:/codex/capacity-save-kiss/')
                || str_starts_with($root, $projectStorage))
            || in_array('..', explode('/', $root), true)) {
            throw new RuntimeException('TEST_DB_OWNERSHIP_ROOT_NOT_LOCAL');
        }
        if (!is_dir($root) && !mkdir($root, 0770, true) && !is_dir($root)) {
            throw new RuntimeException('TEST_DB_OWNERSHIP_DIRECTORY_UNAVAILABLE');
        }
        $this->ownershipJournal = $root . '/k1d-test-databases.jsonl';
        $this->creationAttempt = bin2hex(random_bytes(12));
        // An intent is not ownership. Existing databases must fail, never be adopted.
        $this->recordOwnershipEvent('create_intent');
        $this->admin->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->created = true;
        try {
            $this->recordOwnershipEvent('created');
        } catch (Throwable $error) {
            // The caller must not receive an unrecorded DB. Only this confirmed CREATE is ours.
            $this->cleanup();
            throw $error;
        }
    }

    private function openAdminConnection(): PDO
    {
        return new PDO(
            sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $this->host, $this->port),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASS'),
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ]
        );
    }

    private function recordOwnershipEvent(string $event): void
    {
        $line = json_encode([
            'event' => $event,
            'attempt_id' => $this->creationAttempt,
            'db_name' => $this->dbName,
            'db_host' => $this->host,
            'db_port' => $this->port,
            'pid' => getmypid(),
            'at' => gmdate('c'),
        ], JSON_THROW_ON_ERROR) . "\n";
        $stream = fopen($this->ownershipJournal, 'ab');
        if ($stream === false) {
            throw new RuntimeException('TEST_DB_OWNERSHIP_JOURNAL_UNAVAILABLE');
        }
        try {
            if (!flock($stream, LOCK_EX) || fwrite($stream, $line) !== strlen($line)
                || !fflush($stream) || !fsync($stream)) {
                throw new RuntimeException('TEST_DB_OWNERSHIP_RECORD_NOT_DURABLE');
            }
        } finally {
            flock($stream, LOCK_UN);
            fclose($stream);
        }
    }
}
