<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Session;
use App\Services\InstallerService;
use App\Services\PasswordPolicy;
use PDOException;
use RuntimeException;
use Throwable;

final class InstallController
{
    public function __construct(private readonly string $root)
    {
    }

    public function handle(): void
    {
        if (is_file($this->root . '/storage/install.lock')) {
            http_response_code(423);
            $this->render(true, 'La instalación está bloqueada. Si falta config.env, restaure ese archivo desde una copia privada.');
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(503);
            $this->render(false);
            return;
        }

        $json = (string) ($_POST['action'] ?? '') === 'test';
        try {
            $this->enforceHttps();
            $this->rateLimit();
            Csrf::validate(is_string($_POST['_csrf'] ?? null) ? $_POST['_csrf'] : null);
            $data = $this->validatedInput($_POST, !$json);
            $installer = new InstallerService($this->root);

            if ($json) {
                $version = $installer->testConnection($data);
                $this->json(200, ['ok' => true, 'message' => 'Conexión correcta con MySQL ' . $version . '.']);
                return;
            }

            if (($data['admin_password_confirm'] ?? '') !== $data['admin_password']) {
                throw new RuntimeException('Las contraseñas del administrador no coinciden.');
            }
            $installer->install($data);
            Session::regenerate();
            header('Location: ' . rtrim($data['app_url'], '/') . '/login?installed=1', true, 303);
        } catch (Throwable $exception) {
            $message = $this->safeMessage($exception);
            if ($json) {
                $this->json(422, ['ok' => false, 'message' => $message]);
                return;
            }
            http_response_code(422);
            $this->render(false, $message, $this->safeValues($_POST));
        }
    }

    /** @param array<string,mixed> $source @return array<string,string> */
    private function validatedInput(array $source, bool $forInstall): array
    {
        $keys = ['db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'meli_client_id', 'meli_client_secret', 'admin_name', 'admin_email', 'admin_password', 'admin_password_confirm'];
        $data = [];
        foreach ($keys as $key) {
            $value = $source[$key] ?? '';
            if (!is_string($value) || str_contains($value, "\r") || str_contains($value, "\n")) {
                throw new RuntimeException('La información enviada no es válida.');
            }
            $data[$key] = in_array($key, ['db_pass', 'meli_client_secret', 'admin_password', 'admin_password_confirm'], true) ? $value : trim($value);
        }
        $data['app_url'] = $this->detectedUrl();

        $requiredFields = ['db_host', 'db_port', 'db_name', 'db_user'];
        if ($forInstall) {
            array_push($requiredFields, 'admin_name', 'admin_email');
        }
        foreach ($requiredFields as $required) {
            if ($data[$required] === '') {
                throw new RuntimeException('Complete todos los campos obligatorios.');
            }
        }
        if (!ctype_digit($data['db_port']) || (int) $data['db_port'] < 1 || (int) $data['db_port'] > 65535) {
            throw new RuntimeException('El puerto de MySQL no es válido.');
        }
        if (!preg_match('/^[a-zA-Z0-9._:\\-]+$/', $data['db_host']) || !preg_match('/^[a-zA-Z0-9_$\\-]+$/', $data['db_name'])) {
            throw new RuntimeException('El servidor o el nombre de la base de datos contiene caracteres no válidos.');
        }
        if ($forInstall && !filter_var($data['admin_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo del administrador no es válido.');
        }
        if ($forInstall && !PasswordPolicy::isValid($data['admin_password'])) {
            throw new RuntimeException(PasswordPolicy::MESSAGE);
        }
        $url = parse_url($data['app_url']);
        $urlHost = is_array($url) ? strtolower((string) ($url['host'] ?? '')) : '';
        $localUrl = is_array($url) && in_array($urlHost, ['localhost', '127.0.0.1', '::1'], true) && ($url['scheme'] ?? '') === 'http';
        if (!is_array($url) || ((($url['scheme'] ?? '') !== 'https') && !$localUrl) || $urlHost === '') {
            throw new RuntimeException('La URL del ERP debe ser una dirección HTTPS válida.');
        }
        $currentHost = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
        if ($currentHost !== '' && strtolower((string) $url['host']) !== $currentHost) {
            throw new RuntimeException('La URL del ERP debe pertenecer a este mismo dominio.');
        }
        return $data;
    }

    private function enforceHttps(): void
    {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $host = strtolower(explode(':', (string) ($_SERVER['HTTP_HOST'] ?? ''))[0]);
        if (!$https && !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
            throw new RuntimeException('Por seguridad, el instalador solo funciona mediante HTTPS.');
        }
    }

    private function rateLimit(): void
    {
        $now = time();
        $attempts = array_values(array_filter((array) Session::get('_installer_attempts', []), static fn ($timestamp): bool => is_int($timestamp) && $timestamp > $now - 900));
        if (count($attempts) >= 10) {
            throw new RuntimeException('Demasiados intentos. Espere 15 minutos antes de volver a probar.');
        }
        $attempts[] = $now;
        Session::put('_installer_attempts', $attempts);
    }

    private function safeMessage(Throwable $exception): string
    {
        if (!$exception instanceof PDOException) {
            return \App\Services\SafeErrorPresenter::message($exception, 'No fue posible completar la instalación.');
        }
        $message = strtolower($exception->getMessage());
        return match (true) {
            str_contains($message, 'access denied') => 'MySQL rechazó el usuario o la contraseña.',
            str_contains($message, 'unknown database') => 'La base de datos indicada no existe.',
            str_contains($message, 'connection refused'), str_contains($message, 'no such host'), str_contains($message, 'getaddrinfo') => 'No fue posible localizar o contactar el servidor MySQL.',
            default => 'No fue posible conectar con MySQL. Verifique servidor, puerto, base de datos, usuario y permisos.',
        };
    }

    /** @param array<string,mixed> $source @return array<string,string> */
    private function safeValues(array $source): array
    {
        $safe = [];
        foreach (['db_host', 'db_port', 'db_name', 'db_user', 'meli_client_id', 'admin_name', 'admin_email'] as $key) {
            $safe[$key] = is_string($source[$key] ?? null) ? $source[$key] : '';
        }
        return $safe;
    }

    /** @param array<string,string> $values */
    private function render(bool $locked, ?string $error = null, array $values = []): void
    {
        $csrf = Csrf::token();
        $detectedUrl = $this->detectedUrl();
        $assetBase = rtrim((string) parse_url($detectedUrl, PHP_URL_PATH), '/');
        require $this->root . '/app/Views/errors/setup.php';
    }

    private function detectedUrl(): string
    {
        $https = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
        $scheme = $https ? 'https' : 'http';
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/public/index.php'));
        $base = str_contains($script, '/public/') ? strstr($script, '/public/', true) : dirname($script);
        return $scheme . '://' . $host . rtrim((string) $base, '/.');
    }

    /** @param array<string,mixed> $payload */
    private function json(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
