# ERP MELI 2.36.3 — reparación por FTP e instalación en subcarpeta

## Autoridad

Use únicamente `ERP_MELI_2.36.3_FTP_REPAIR_OVERLAY.zip` junto con su inventario JSON y compruebe primero su SHA-256 en `ERP_MELI_2.36.3_ARTIFACT_MANIFEST.json`. El overlay se construye desde blobs Git exactos y contiene sólo los archivos de runtime añadidos o modificados respecto de `prod-2.36.2`.

La instalación puede vivir en cualquier subcarpeta del document root. Para una URL como `https://ejemplo.test/erp-meli/`, extraiga el contenido del overlay dentro de la carpeta física que ya contiene `VERSION`, `bootstrap.php`, `actualizar.php`, `app/` y `resources/`; no dentro del document root ni dentro de una segunda carpeta anidada.

## Antes de subir

1. Conserve un respaldo descargable del runtime actual y de la base de datos.
2. Detenga API y automatización mediante los marcadores ya existentes.
3. Compruebe que la carpeta destino corresponde a la instalación correcta y que `VERSION` es 2.36.2 o el estado mixto diagnosticado.
4. Compare el inventario del overlay. Deben ser exactamente los paths declarados; no agregue carpetas completas del backup histórico.
5. Use transferencia binaria y conserve la estructura relativa de paths.

## FTP nunca debe sobrescribir

- `config.env`, `.env` o `shared/config.env`.
- `storage/` ni `shared/storage/`, en especial cualquier `storage/raw`.
- logs, cache, sesiones, OAuth, payloads, respaldos o archivos temporales.
- `PAUSE_MELI_API` y `PAUSE_ERP_AUTOMATION`.
- `shared/current-release.json` ni otros punteros/markers de operación.
- una carpeta completa `app/`, `resources/`, `database/` o el runtime histórico: suba únicamente los archivos del inventario.

## Verificación local y en hosting

Después de subir, abra `actualizar.php` bajo la misma subcarpeta. La pantalla debe reconocer 2.36.3, schema 293 y ofrecer la finalización metadata-only. Esta finalización no ejecuta migraciones y sólo puede modificar `app_settings['app.version']`, la fila `app_versions['2.36.3']` y el marker instalado.

No use el overlay si el hash no coincide, si falta un archivo, si aparece una ruta adicional o si la instalación activa no es la carpeta esperada. En ese caso deténgase y restaure los archivos exactos del respaldo; no mezcle nuevamente carpetas completas.

## Rollback

Antes de la confirmación final, restaure los archivos reemplazados desde el respaldo exacto y conserve intactos configuración y storage. Si la transición metadata-only empezó y falló, el servicio 2.36.3 restaura mediante CAS la preimagen de `app.version`, `app_versions` y marker; si detecta una postimagen ajena, bloquea en lugar de sobrescribirla.
