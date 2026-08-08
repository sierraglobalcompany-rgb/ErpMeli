<?php

declare(strict_types=1);

use App\Core\Database;
use App\Services\PasswordPolicy;

require dirname(__DIR__) . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$args = is_array($_SERVER['argv'] ?? null) ? $_SERVER['argv'] : [];
[$script, $name, $email] = array_pad($args, 3, null);
if (!$name || !$email) {
    fwrite(STDERR, "Uso: php bin/create_admin.php \"Nombre\" correo@empresa.com\n");
    exit(1);
}
$password = getenv('ERP_ADMIN_PASSWORD');
if (!is_string($password) || !PasswordPolicy::isValid($password)) {
    fwrite(STDERR, PasswordPolicy::MESSAGE . "\n");
    exit(1);
}
$stmt = Database::connection()->prepare(
    "INSERT INTO users (name, email, password_hash, role, status) VALUES (:name, :email, :password, 'admin', 1)"
);
$stmt->execute([
    'name' => $name,
    'email' => strtolower($email),
    'password' => password_hash($password, PASSWORD_DEFAULT),
]);
echo "Administrador creado. Elimine ERP_ADMIN_PASSWORD del entorno.\n";
