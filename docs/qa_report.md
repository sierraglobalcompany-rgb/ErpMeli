# Informe de QA — V0.1

## Comprobaciones ejecutadas

- 60 archivos PHP analizados correctamente con parser PHP 8.
- JavaScript validado con `node --check`.
- 25 tablas incluidas en la migración inicial.
- Escaneo de patrones de secretos: sin credenciales reales detectadas.
- `ML_WRITE_ENABLED=false` incluido en `config.env.example` y aplicado por `WriteGuard` dentro del cliente central.
- Rutas internas protegidas por `.htaccess`; jobs y comandos rechazan ejecución HTTP.
- Render estático validado a 1440×1000 y 390×844 mediante Edge headless.

## Fidelidad visual

Concepto de referencia: `docs/design/dashboard-concept.png`.

1. Navegación lateral azul marino, jerarquía y estado activo: coinciden.
2. Barra superior y selectores de empresa/cuenta: coinciden y son controles reales.
3. Cuatro KPI, escala tipográfica y números tabulares: coinciden.
4. Distribución gráfico/donut/sincronizaciones: coincide con adaptación responsable a la anchura disponible.
5. Tabla de órdenes, badges y densidad de filas: coinciden.
6. Fondo gris frío, superficies blancas, bordes finos y azul de acción: coinciden.
7. Móvil: sidebar colapsable, KPI apilados, acciones sin desbordamiento y tablas con scroll horizontal.

No se añadieron textos promocionales sobre el pliegue. Los datos del render son ficticios y solo se usaron para QA visual; no forman parte de la aplicación ni de la base.

## Limitaciones del entorno de construcción

- No había binario PHP ni servidor MySQL disponible, por lo que `php tests/run.php`, `php bin/lint.php` y una migración real deben ejecutarse en staging/Hostinger.
- Mercado Libre Developers Colombia no respondió desde el entorno. El mapa conserva cada endpoint como `Pendiente de revalidación`; la entrega no debe promoverse a producción hasta completar esa compuerta.
- No se ejecutó OAuth con una cuenta real porque no se proporcionaron credenciales, correctamente.

## Aceptación pendiente en staging

Ejecutar migraciones, pruebas unitarias, conexión OAuth de la cuenta de bajo volumen, doble sincronización idempotente, recepción de webhook, generación/aprobación/exportación de reporte y prueba negativa de mutaciones.
