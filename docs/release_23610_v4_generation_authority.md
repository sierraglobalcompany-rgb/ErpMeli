# ERP MELI 2.36.10 — instalación del hotfix de generaciones V4

1. No cree Cron V4 ni active Queue Engine antes de instalar esta versión.
2. Suba el contenido de `ERP_MELI_2.36.10_FTP_REPAIR_OVERLAY.zip` dentro de la carpeta existente `erp-meli`.
3. No sobrescriba `config.env`, `storage`, `shared/storage`, los frenos ni el pointer administrado.
4. Abra `/erp-meli/actualizar.php` y complete la actualización metadata-only a 2.36.10.
5. Regrese a `/erp-meli/settings/cron` y confirme que la tarjeta V4 muestre `ready_to_arm`.
6. No pulse automáticamente: revise primero el estado y envíe la captura.

La release conserva generaciones monotónicas; no ejecute SQL para reiniciarlas.
