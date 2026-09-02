# Actualización ERP Meli 2.21.1

## Qué corrige

- Control de ventas deja de consultar la columna inexistente `meli_accounts.deleted_at`.
- La lectura de cuentas queda aislada por empresa y conserva el historial de cuentas desconectadas.
- Una cuenta desconectada permite consultar evidencia anterior, pero exige reautorización para crear nuevas comprobaciones.
- La cola de auditorías utiliza su fecha real `started_at`.
- Si el esquema está incompleto, la pantalla explica que debe completar la actualización en vez de producir un error 500.
- Los fallos repetidos de esta incompatibilidad aparecen agrupados como un incidente recuperable.

## Instalación

1. Respalde archivos y base de datos.
2. Copie el contenido de la carpeta `SUBIR` sobre la instalación.
3. Abra **Configuración → Actualizaciones**.
4. Ejecute **Completar actualización** para aplicar `113_sales_control_schema_compatibility_2_21_1.sql`.
5. Confirme versión instalada `2.21.1` y cero migraciones pendientes.
6. Abra **Ventas → Control de ventas**.
7. Seleccione la cuenta y el año 2026.
8. En enero, pulse **Comprobar enero** una sola vez y compruebe que el trabajo aparezca en Automatización.

La acción web solo encola la comprobación. No consulta ni modifica Mercado Libre. Mantenga `ML_WRITE_ENABLED=false`.
