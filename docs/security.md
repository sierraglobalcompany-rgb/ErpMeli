# Seguridad

- Tokens cifrados con AES-256-GCM y `APP_KEY` externa.
- Sesiones HTTP-only, Secure y SameSite Lax; regeneración tras login.
- CSRF en todo formulario mutador y escape HTML en vistas.
- PDO preparado sin emulación.
- Cinco intentos fallidos por correo/IP en 15 minutos.
- Roles: consulta, operador y administrador.
- Solo administradores gestionan usuarios, empresas, cuentas y estados finales de reportes.
- `ML_WRITE_ENABLED=false` bloquea mutaciones comerciales en el cliente central.
- OAuth `state` aleatorio, hasheado, expira y se consume una vez.
- Logs y errores pasan por sanitización; no se registran encabezados Authorization ni secretos.
- `.htaccess` niega acceso a código, configuración, base de datos, storage, pruebas y vendor.

## Incidentes

Ante sospecha de filtración: revoque la aplicación/cuenta en Mercado Libre, rote client secret y APP_KEY, elimine tokens cifrados anteriores, reconecte cada cuenta y revise `audit_logs` y `api_error_logs`.
