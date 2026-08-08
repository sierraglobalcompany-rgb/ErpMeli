<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};
$read = static fn (string $path): string => (string) file_get_contents($root . '/' . $path);

$doctor = $read('app/Services/CronDoctorService.php');
$cron = $read('jobs/process_sync_queue.php');
$coordinator = $read('app/Services/CronWorkCoordinator.php');
$taskState = $read('app/Services/CronTaskStateService.php');
$controller = $read('app/Controllers/SettingsController.php');
$routes = $read('public/index.php');

$check(str_contains($doctor, 'final class CronDoctorService'), 'Falta CronDoctorService.');
$check(str_contains($doctor, "'read_only' => true"), 'Cron Doctor debe declararse read-only.');
$check(!preg_match('/\\b(INSERT|UPDATE|DELETE|REPLACE|TRUNCATE|ALTER|CREATE|DROP)\\b/i', $doctor),
    'CronDoctorService no debe contener SQL de mutación.');
$check(str_contains($cron, '--doctor'), 'process_sync_queue.php no expone --doctor.');
$check(strpos($cron, '--doctor') !== false
    && strpos($cron, '--doctor') < strpos($cron, 'cron_entry_state_write'),
    '--doctor debe ejecutarse antes de locks/health writes.');
$check(str_contains($cron, '--qa-replay') && str_contains($cron, '--fake-transport'),
    'QA replay debe exigir transporte falso.');
$check(str_contains($routes, "/settings/cron/doctor.json"), 'Falta ruta /settings/cron/doctor.json.');
$check(str_contains($controller, 'public function cronDoctor'), 'Falta método cronDoctor en SettingsController.');
$check(str_contains($coordinator, "CronWorkOutcome::isWaiting((string) (\$result['status'] ?? ''))")
    && str_contains($coordinator, '? 0'),
    'Las esperas no deben inflar completed_count.');
$check(str_contains($coordinator, "str_starts_with(\$status, 'waiting_')"),
    'Las esperas waiting_* no deben contar como started sin HTTP real.');
$check(str_contains($taskState, '$notStarted > 0 && $started === 0')
    && str_contains($taskState, ': 5;'),
    'not_started_deadline debe volver pronto, no quedar en cooldown de 60 segundos.');

for ($version = 49; $version <= 58; $version++) {
    $migration = glob($root . '/database/migrations/*_2_28_' . $version . '.sql');
    $check($migration !== [], 'Falta migración 2.28.' . $version . '.');
}

if ($failures !== []) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}

echo "PASS cron_reconstruction_doctor_22858\n";
