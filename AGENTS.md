# AGENTS.md

- PHP 8.3–8.5, MySQL/MariaDB y PDO MySQL exclusivamente.
- Toda consulta de negocio debe estar aislada por `meli_account_id` y empresa.
- No registrar ni mostrar secretos o tokens. Mantenerlos cifrados en reposo.
- Ninguna mutación remota se permite con `ML_WRITE_ENABLED=false`.
- No implementar endpoints que no estén confirmados en `docs/mercadolibre_api_map.md`.
- Los procesos automáticos son CLI. La única excepción permitida es el ejecutor web manual de
  `Procesar ahora`: una petición administrativa autenticada puede procesar un solo paso
  idempotente mientras la pestaña permanezca abierta. No puede iniciar jobs, procesar una cola
  completa ni continuar en segundo plano.
- Mantener sincronizaciones idempotentes y cierres mensuales inmutables.
