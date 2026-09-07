<?php
declare(strict_types=1);
require __DIR__ . '/k1b_bootstrap.php';
require __DIR__ . '/K1dSafeTestDatabase.php';

use App\Services\CapacityPolicyService;
use App\Services\AutomationCallBudgetService;
use App\Services\AppSettingsService;

k1b_assert(class_exists(CapacityPolicyService::class), 'shared_capacity_policy_missing');
putenv('APP_ENV=test');
putenv('ML_WRITE_ENABLED=false');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=' . (getenv('DB_PORT') ?: '33079'));
putenv('DB_USER=root');
putenv('DB_PASS=');
putenv('DB_NAME=erp_meli_k1d_test_capacity_' . bin2hex(random_bytes(4)));
$harness = K1dSafeTestDatabase::createFromEnvironment();
try {
    $pdo = $harness->pdo();
    $pdo->exec('CREATE TABLE app_settings (setting_key VARCHAR(190) PRIMARY KEY,setting_value TEXT NULL,is_encrypted TINYINT NOT NULL DEFAULT 0,setting_group VARCHAR(80) NOT NULL DEFAULT "general",updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB');
    $policy = new CapacityPolicyService($pdo);
    $settings = new AppSettingsService();
    $auto = $policy->snapshot('automation');
    $manual = $policy->snapshot('manual');
    k1b_assert($auto['current'] === 1 && $auto['ceiling'] === 55, 'preserve_default_auto_one');
    k1b_assert($manual['current'] === 1 && $manual['ceiling'] === 55 && !$manual['legacy_derived'], 'missing_manual_calls_defaults_to_one');
    k1b_assert((int) $pdo->query('SELECT COUNT(*) FROM app_settings')->fetchColumn() === 0, 'reads_do_not_install_settings');
    $settings->set('automation.max_api_calls_per_cycle','99');
    k1b_assert($policy->snapshot('automation')['current'] === 55, 'explicit_auto_calls_bounded_by_default_ceiling');
    $pdo->exec("DELETE FROM app_settings WHERE setting_key='automation.max_api_calls_per_cycle'");
    AppSettingsService::clearCache();
    foreach ([['conservative', null], ['maximum', null], ['custom', '3'], ['custom', '0'], ['fast', '3']] as [$profile,$target]) {
        $settings->set('api.rhythm.profile', $profile);
        $settings->set('api.rhythm.target_http_per_minute', $target);
        k1b_assert($policy->snapshot('manual')['current'] === 1, 'legacy_rhythm_not_capacity_' . $profile . '_' . $target);
        k1b_assert(!$policy->requiresManualAdoptionForRhythm($profile, $target === null ? 30 : max(1, (int) $target)), 'legacy_rhythm_never_requires_call_adoption_' . $profile);
    }
    $settings->set('manual_campaign.default_block_size', '2');
    k1b_assert($policy->snapshot('manual')['current'] === 1, 'legacy_manual_block_size_not_capacity');
    k1b_assert($policy->snapshot('automation')['revision'] === $auto['revision'], 'modules_independent_before_save');
    $gateCalls = 0;
    $allow = static function () use (&$gateCalls): array { $gateCalls++; return ['allowed'=>true,'message'=>'']; };
    foreach ([1,2,3,15,55,100] as $value) {
        $before = $policy->snapshot('automation');
        $after = $policy->save('automation', (string) $value, (string) max(55,$value), $before['revision'], $allow);
        k1b_assert($after['current'] === $value && $after['ceiling'] === max(55,$value), 'save_boundary_' . $value);
        k1b_assert((new AutomationCallBudgetService())->resolve()['max_calls'] === $value, 'runtime_boundary_' . $value);
    }
    k1b_assert($gateCalls === 5, 'gate_only_actual_current_increases');
    foreach ([0,101,-1,1.0,true,null,'','2.5','1e1',' 2','02',[],str_repeat('9',30)] as $bad) {
        $before = $policy->snapshot('automation');
        foreach ([[$bad,100],[1,$bad]] as [$current,$ceiling]) {
            $rejected = false;
            try { $policy->save('automation',$current,$ceiling,$before['revision'],$allow); }
            catch (InvalidArgumentException) { $rejected = true; }
            k1b_assert($rejected, 'strict_integer_rejected_' . json_encode([$current,$ceiling]));
            k1b_assert($policy->snapshot('automation') === $before, 'invalid_save_no_mutation');
        }
    }
    $before = $policy->snapshot('automation');
    $after = $policy->save('automation', 1, 55, $before['revision'], $allow);
    $stale = false;
    try { $policy->save('automation',2,55,$before['revision'],$allow); } catch (RuntimeException) { $stale = true; }
    k1b_assert($stale && $policy->snapshot('automation') === $after, 'stale_revision_does_not_overwrite');
    $denied = false;
    try { $policy->save('automation',2,55,$after['revision'],static fn (): array => ['allowed'=>false,'message'=>'health_denied']); }
    catch (RuntimeException $error) { $denied = $error->getMessage() === 'health_denied'; }
    k1b_assert($denied && $policy->snapshot('automation') === $after, 'unhealthy_increase_rolls_back');
    $noGate = static function (): array { throw new RuntimeException('must_not_gate_ceiling_only'); };
    $ceilingOnly = $policy->save('automation',1,100,$after['revision'],$noGate);
    k1b_assert($ceilingOnly['current'] === 1 && $ceilingOnly['ceiling'] === 100, 'ceiling_only_keeps_current');
    $small = $policy->save('automation',2,3,$ceilingOnly['revision'],$allow);
    k1b_assert((new AutomationCallBudgetService())->resolve(100)['max_calls'] === 2, 'cli_override_cannot_raise_current');
    k1b_assert((new ReflectionMethod(AutomationCallBudgetService::class, 'resolve'))->getNumberOfParameters() === 1, 'budget_service_has_no_legacy_jobs_parameter');
    $pdo->exec('CREATE TABLE queue_v4_clean_control (control_key VARCHAR(32) PRIMARY KEY,engine_state VARCHAR(20),scheduler_enabled TINYINT) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO queue_v4_clean_control VALUES ('primary','STOPPED',0)");
    $stopped = (new App\QueueV4Clean\QueueV4CleanScheduler($pdo))->run(100);
    k1b_assert($stopped['max_calls'] === 2 && $stopped['status'] === 'stopped', 'scheduler_entry_uses_configured_current');
    k1b_assert($policy->snapshot('manual')['current'] === 1 && $policy->snapshot('manual')['ceiling'] === 55, 'manual_unmodified_by_automation');
    $manual = $policy->snapshot('manual');
    $savedManual = $policy->save('manual',55,55,$manual['revision'],$allow);
    $settings->set('api.rhythm.target_http_per_minute','1');
    k1b_assert($policy->snapshot('manual')['current'] === 55, 'explicit_manual_not_legacy_rhythm_clamped');
    // A failed second write must not expose half a pair.
    $pdo->exec("CREATE TRIGGER reject_capacity_ceiling BEFORE UPDATE ON app_settings FOR EACH ROW BEGIN IF NEW.setting_key='automation.api_calls_ceiling' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='fixture_write_failure'; END IF; END");
    $failed = false;
    try { $policy->save('automation',3,55,$small['revision'],$allow); } catch (PDOException) { $failed = true; }
    k1b_assert($failed && $policy->snapshot('automation') === $small, 'pair_save_atomic_on_db_failure');
    $pdo->exec('DROP TRIGGER reject_capacity_ceiling');
    $pdo->exec('DROP TABLE app_settings');
    $failed = false;
    try { $policy->snapshot('automation'); } catch (PDOException) { $failed = true; }
    k1b_assert($failed, 'database_errors_not_defaults');
    echo "STATUS=PASS CAPACITY_POLICY_MYSQL\nREAL_MELI_HTTP=0\nREAL_EMAIL_SENT=0\n";
} finally { $harness->cleanup(); }
