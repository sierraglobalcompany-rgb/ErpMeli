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

## Ley 1 — KISS + Ponytail (adaptación, 2026-10-24)
Antes de modificar código, consultar `.agents/skills/kiss-ponytail/SKILL.md` cuando exista y cumplir L1–L6 del canon vigente. Escalera: NO CREAR lo innecesario → REUTILIZAR repo → stdlib/nativo → dependencia existente → mínima solución legible. Verificar consumidores, contratos, riesgos y pruebas; preservar seguridad, datos, permisos, diseño aprobado y QA. No ejecutar `ponytail-audit` por rutina. Plugin global y hooks de Codex requieren instalación/confianza separadas; este archivo no prueba activación.
