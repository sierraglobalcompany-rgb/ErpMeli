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

    private function __construct()
    {
        $this->host = (string) getenv('DB_HOST');
        $this->port = (string) (getenv('DB_PORT') ?: '3306');
        $this->dbName = (string) getenv('DB_NAME');
        $this->assertGuard((string) getenv('APP_ENV'), (string) getenv('ML_WRITE_ENABLED'), $this->host, $this->dbName);

        $this->admin = new PDO(
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

    public function cleanup(): void
    {
        if (!$this->created) {
            return;
        }
        self::assertGuard((string) getenv('APP_ENV'), (string) getenv('ML_WRITE_ENABLED'), $this->host, $this->dbName);
        $this->admin->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
        $this->created = false;
    }

    private function createDatabase(): void
    {
        $this->admin->exec('CREATE DATABASE IF NOT EXISTS `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $this->created = true;
    }
}
