# Instalación en Hostinger

## Base de datos y archivos

1. Cree una base MySQL/MariaDB UTF-8 y un usuario exclusivo.
2. Suba el contenido a `public_html/erp-meli`; no incluya `config.env`, logs, certificados ni respaldos dentro de archivos de actualización.
3. Compruebe que `.htaccess` está habilitado. Las carpetas internas deben responder 403 y `/erp-meli/` debe cargar `public/index.php`.
4. Abra el ERP por HTTPS y use el instalador web. Este comprobará MySQL antes de crear `config.env`.
5. Para hacerlo manualmente, copie `config.env.example` como `config.env`, complete las credenciales y genere `APP_KEY` una sola vez.
6. En una instalación manual, ejecute `composer install --no-dev --optimize-autoloader`, `php bin/migrate.php` y el comando de creación del administrador.

El ZIP de instalación y las actualizaciones solo contienen `config.env.example`; nunca contienen `config.env`. Por eso extraer una versión nueva sobre el ERP no reemplaza las credenciales existentes.

## Prueba funcional

1. Ingrese como administrador y cree las dos empresas internas.
2. Conecte primero la cuenta Mercado Libre de bajo volumen.
3. Verifique que la pantalla muestre nickname, usuario y estado conectado, sin mostrar tokens.
4. Sincronice un rango corto y repítalo: los conteos de registros no deben aumentar por duplicados.
5. Confirme orden, productos, pago y envío desde el detalle.
6. Genere un reporte mensual, revise la base, apruébelo y exporte CSV.
7. Intente una mutación desde una prueba controlada: debe fallar con `ML_WRITE_ENABLED=false`.
8. Revise `/logs` y confirme que no aparecen secretos.

## Permisos recomendados

- Directorios: `0750` o `0755` según el hosting.
- Archivos: `0640` o `0644`.
- `config.env`: permiso `0600` cuando el hosting lo permita. `.htaccess` bloquea además cualquier solicitud HTTP a `config.env`, su ejemplo y sus archivos temporales.
- `storage/`: escritura para PHP, sin acceso HTTP.

## Actualizaciones

Haga respaldo de base y `config.env`, despliegue la actualización, ejecute migraciones y valide primero con la cuenta de prueba. Nunca copie tokens entre ambientes.
