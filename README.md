# ERP Meli / Gestión Pro 2.8.22

ERP interno multicuenta para órdenes, productos, catálogos, sincronización, conciliación financiera y facturación operativa de Mercado Libre. La integración remota opera en **solo lectura**.

## Requisitos

- PHP 8.3, 8.4 o 8.5 con PDO MySQL, cURL, JSON, OpenSSL, mbstring, session e iconv.
- MySQL 5.7+/MariaDB 10.4+.
- Apache con `mod_rewrite` para la instalación bajo `/erp-meli`.
- Composer solo para generar el autoload optimizado; existe un autoload mínimo de respaldo.

## Instalación rápida

1. Copiar el proyecto a `public_html/erp-meli`.
2. Crear la base de datos y un usuario con privilegios limitados a esa base.
3. Abrir por HTTPS la URL del ERP. El instalador detecta automáticamente dominio y carpeta; solo solicita base, usuario y contraseña. `localhost:3306` queda disponible como configuración avanzada. La información privada se guarda en `config.env`.
4. Registrar en Mercado Libre exactamente las URL mostradas por el ERP.
5. Configurar los cron descritos en `docs/cron_jobs.md`.

El instalador se deshabilita automáticamente al terminar. Para una instalación manual, copie `config.env.example` como `config.env`; después ejecute `php bin/migrate.php` y `php bin/create_admin.php`. El ZIP nunca contiene `config.env`, por lo que una actualización no reemplaza las credenciales existentes.

Si las credenciales de la aplicación Mercado Libre se omiten durante la instalación, un administrador puede guardarlas después en **Cuentas Meli**. El ERP no inicia OAuth mientras falten Client ID, Client Secret o URL de redirección.

No coloque secretos en comandos persistentes, repositorio, README o capturas. Consulte [la instalación completa](docs/installation.md).

## Módulos principales

- Login, roles y auditoría.
- Empresas internas y cuentas Mercado Libre cifradas por cuenta.
- OAuth Authorization Code con `state` de consumo único.
- Órdenes, productos vendidos, packs, envíos y pagos.
- Webhooks persistidos y procesamiento asíncrono.
- Reporte mensual por pago aprobado, CSV y ajustes posteriores.
- Dashboard, logs sanitizados y jobs CLI.

## Seguridad operativa

`ML_WRITE_ENABLED=false` es el valor obligatorio. El cliente central bloquea toda petición clasificada como mutación. OAuth usa POST porque es un flujo de autenticación, no una mutación comercial.

## Verificación

```bash
composer validate --strict
composer check-platform-reqs
php bin/lint.php
php bin/php_compat.php
vendor/bin/phpstan analyse
php tests/run.php
php tests/route_contract.php
php bin/migrate.php
```

Antes de producción, revalide el mapa de endpoints contra Mercado Libre Developers Colombia y ejecute la lista de aceptación de `docs/installation.md`.
