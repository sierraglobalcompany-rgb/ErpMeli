---
name: kiss-ponytail
description: Ley 1 KISS estricta mediante Ponytail. Aplicar en tareas de programación, bug fixes y refactors; preservar leyes 2 a 6.
---
# Ley 1 KISS + Ponytail — contrato local
Origen: https://github.com/DietrichGebert/ponytail, fuente oficial v5.3.2 commit 09c434ab73ca43761c5d8b0c5d341926c2fba710, licencia MIT. Adaptación del proyecto, NO plugin oficial instalado.

**Primero leer flujo y consumidores:** requisito actual → entradas → auth/permiso/tenant → servicio → DB/UI → errores y pruebas. No juzgar por líneas aisladas ni borrar consumidores dinámicos sin comprobar.

**Escalera de menor solución completa:** (1) no crear función no solicitada; (2) reutilizar funciones, componentes y contratos del repositorio; (3) biblioteca estándar o capacidad nativa compatible; (4) dependencia ya instalada; (5) expresión simple y legible; (6) mínimo código nuevo suficiente. La solución más corta que incumple un requisito NO cumple KISS.

**Descartar con evidencia:** wrappers vacíos, factories sin necesidad, flags especulativos, helpers duplicados, dependencias para acciones triviales, complejidad y capas innecesarias. Comprobar consumidores estáticos/dinámicos, compatibilidad y pruebas antes de unir o retirar; desconocido no es basura.

**No sacrificar:** contrato aprobado, controles de seguridad, validación, permisos/aislamiento de empresas, integridad y reversión de datos, casos negativos, acceso humano/accesibilidad y pruebas realmente necesarias. Un bug exige corregir causa raíz y consumidores, no acumular parches. Test de lógica nueva con rama/parser/dinero/auth/persistencia cuando aplique; una prueba no limita los gates de Ley 5.

**Uso proporcional:** revisión Ponytail de diff/PR solo si aporta valor; auditoría de repo solo si fue solicitada o el riesgo lo exige. Su tope de 20 hallazgos no oculta P0/P1 del procedimiento forense. No invocar hooks ni instalar scripts automáticamente.

**Seis leyes:** L1 KISS Ponytail; L2 antirruido; L3 antiparches; L4 UX/UI aprobada (Anti UI Slop + UI UX Pro Max como fuentes complementarias); L5 aceptación con evidencia independiente; L6 ChatGPT primero y Codex solo como ejecutor de lote justificado. `full` es intensidad ordinaria; `ultra` no revoca contratos del usuario.

**Salida:** objetivo, alternativa elegida, código/consumidores afectados, pruebas reales PASS/FAIL/BLOCKED/NOT_RUN, riesgos, NEXT/NINGUNO. No inventar PASS, no modificar ramas o producción sin permiso.
