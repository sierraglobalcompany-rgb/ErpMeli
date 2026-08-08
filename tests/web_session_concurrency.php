<?php

declare(strict_types=1);

/**
 * Sonda conductual del lock de sesión.
 *
 * seed crea una sesión autenticada de prueba. Cada worker abre exactamente la
 * misma sesión, toma su snapshot, libera el lock y simula 500 ms de trabajo.
 * Veinte workers no deben serializarse durante diez segundos.
 */

$mode = (string) ($argv[1] ?? '');
$sessionId = preg_replace('/[^A-Za-z0-9,-]/', '', (string) ($argv[2] ?? 'erp2259-session-probe'));
if ($sessionId === '') {
    fwrite(STDERR, "ERROR: id de sesión inválido.\n");
    exit(2);
}

putenv('SESSION_SECURE=false');
putenv('APP_KEY=session-probe-key-2259-which-is-long-enough');
require dirname(__DIR__) . '/bootstrap.php';

session_id($sessionId);
\App\Core\Session::start();

if ($mode === 'seed') {
    \App\Core\Session::put('user', [
        'id' => 1,
        'name' => 'Probe',
        'email' => 'probe@example.invalid',
        'role' => 'admin',
        'is_temporary' => 0,
        'expires_at' => null,
    ]);
    \App\Core\Session::closeReadOnly();
    echo "SEEDED\n";
    exit(0);
}

if ($mode !== 'worker') {
    fwrite(STDERR, "ERROR: use seed o worker.\n");
    exit(2);
}
if (!is_array(\App\Core\Session::get('user'))) {
    fwrite(STDERR, "ERROR: no se pudo leer el snapshot autenticado.\n");
    exit(1);
}

\App\Core\Session::releaseReadOnlySnapshot();
usleep(500_000);
echo "OK\n";
