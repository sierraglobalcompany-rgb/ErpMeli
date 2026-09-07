<?php

declare(strict_types=1);

namespace App\Core {
    final class Auth
    {
        public static function requireRole(string ...$roles): void {}
        public static function isTemporary(): bool { return false; }
        public static function id(): int { return 7; }
    }
}

namespace {
    require __DIR__ . '/k1b_bootstrap.php';
    require __DIR__ . '/K1dSafeTestDatabase.php';

    use App\Core\Database;
    use App\Services\AppSettingsService;

    if (isset($argv[1])) {
        $_ENV['APP_URL'] = 'https://local.test';
        $_SERVER['HTTP_ORIGIN'] = 'https://local.test';
        $_SESSION = ['_csrf' => 'cap2-csrf'];
        $_POST = [
            '_token' => 'cap2-csrf',
            'profile' => (string) $argv[1],
            'adaptive_enabled' => '1',
        ];
        (new App\Controllers\SettingsController())->saveCronRhythm();
    }

    putenv('APP_ENV=test');
    putenv('ML_WRITE_ENABLED=false');
    putenv('DB_HOST=127.0.0.1');
    putenv('DB_PORT=' . (getenv('DB_PORT') ?: '33079'));
    putenv('DB_USER=root');
    putenv('DB_PASS=');
    putenv('DB_NAME=erp_meli_k1d_test_cap2_writer_controller_' . bin2hex(random_bytes(4)));
    $db = K1dSafeTestDatabase::createFromEnvironment();
    try {
        $pdo = $db->pdo();
        $pdo->exec('CREATE TABLE app_settings (
            setting_key VARCHAR(190) PRIMARY KEY,
            setting_value TEXT NULL,
            is_encrypted TINYINT NOT NULL DEFAULT 0,
            setting_group VARCHAR(80) NOT NULL DEFAULT "general",
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB');
        $seed = $pdo->prepare('INSERT INTO app_settings (setting_key,setting_value,is_encrypted,setting_group) VALUES (?,?,0,"legacy") ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');
        foreach (['api.rhythm.mode' => 'maximum', 'api.rhythm.profile' => 'maximum', 'api.rhythm.target_http_per_minute' => '40', 'manual_campaign.default_block_size' => '30'] as $key => $value) {
            $seed->execute([$key, $value]);
        }

        $run = static function (string $profile): array {
            $process = proc_open([PHP_BINARY, __FILE__, $profile], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            return [proc_close($process), $stdout, $stderr];
        };

        [$exit, $stdout, $stderr] = $run('conservative');
        k1b_assert($exit === 0, 'legacy_change_process_failed:' . $stdout . $stderr);
        k1b_assert($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='api.rhythm.profile'")->fetchColumn() === 'conservative', 'legacy_rhythm_change_blocked_as_capacity');

        k1b_assert((int) ($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='manual.api_calls_per_step'")->fetchColumn() ?: 1) === 1, 'legacy_rhythm_changed_manual_calls');

        $seed->execute(['api.rhythm.mode', 'maximum']);
        $seed->execute(['api.rhythm.profile', 'maximum']);
        $seed->execute(['api.rhythm.target_http_per_minute', '40']);
        $seed->execute(['manual.api_calls_per_step', '15']);
        $seed->execute(['manual.api_calls_ceiling', '55']);
        AppSettingsService::clearCache();
        [$exit, $stdout, $stderr] = $run('conservative');
        k1b_assert($exit === 0, 'adopted_change_process_failed:' . $stdout . $stderr);
        k1b_assert($pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='api.rhythm.profile'")->fetchColumn() === 'conservative', 'adopted_manual_pair_did_not_release_rhythm_writer');
        k1b_assert((int) $pdo->query("SELECT setting_value FROM app_settings WHERE setting_key='manual.api_calls_per_step'")->fetchColumn() === 15, 'rhythm_writer_changed_adopted_manual_current');

        echo "STATUS=PASS CAP2_WRITERS_CONTROLLER REAL_CONTROLLER=YES REAL_MYSQL=YES REAL_MELI_HTTP=0\n";
    } finally {
        $db->cleanup();
    }
}
