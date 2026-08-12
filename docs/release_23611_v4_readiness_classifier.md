# ERP MELI 2.36.11 — subida FTP y recuperación V4

1. No cree todavía el Cron V4 ni active Queue Engine.
2. Extraiga `ERP_MELI_2.36.11_FTP_REPAIR_OVERLAY.zip` dentro de la instalación existente `erp-meli`.
3. No sobrescriba `config.env`, `.env`, `storage`, `shared/storage`, stops ni secretos.
4. Abra `/erp-meli/actualizar.php` y complete la transición metadata-only a 2.36.11.
5. Recargue `/erp-meli/settings/cron`. El incidente 2.36.10 debe aparecer como `recovery_required`.
6. Escriba la contraseña y pulse una sola vez. Exija `recovered_fail_closed`.
7. Recargue y exija `ready_to_arm`; deténgase antes de una segunda pulsación.

El `.erpupd` se entrega como artefacto reproducible, pero este despliegue usa el overlay FTP por el fallo reciente de preparación del actualizador.
