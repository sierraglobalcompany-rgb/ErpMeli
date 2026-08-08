# Automatización en Hostinger

## Instalación clásica confirmada

La instalación de `bodegadigitalmedellin.com/erp-meli` usa rutas directas bajo
`public_html/erp-meli/jobs`. En hPanel seleccione **Personalizado**; la
programación se configura en los cinco selectores y no se pega dentro del
campo del comando.

Durante shadow se conserva el lanzador V2:

```cron
* * * * * /usr/bin/php /home/USUARIO/domains/bodegadigitalmedellin.com/public_html/erp-meli/jobs/process_sync_queue.php
```

Después de instalar el candidato certificado, los dos lanzadores V3 se crean
también cada minuto, primero exclusivamente con `--shadow`:

```cron
* * * * * /usr/bin/php /home/USUARIO/domains/bodegadigitalmedellin.com/public_html/erp-meli/jobs/cron_v3_local.php --shadow --runtime=45 --max-items=50
* * * * * /usr/bin/php /home/USUARIO/domains/bodegadigitalmedellin.com/public_html/erp-meli/jobs/cron_v3_remote.php --shadow --delay=10 --runtime=35 --max-http=12
```

Los argumentos son parte del comando Personalizado. No use `wget`, rutas HTTP,
redirecciones a `/dev/null` ni el tipo PHP de hPanel. La salida debe permanecer
visible durante la certificación.

El shadow requiere:

```env
CRON_V3_ENABLED=false
CRON_V3_SHADOW_ENABLED=true
CRON_V3_RATE_LIMIT=10
ML_WRITE_ENABLED=false
```

La autoridad de activación es `App\Core\Env`: primero variables del proceso y
después `config.env`. Los valores homónimos de `app_settings` son informativos
y el doctor reporta cualquier divergencia.

En una instalación administrada con `shared/current-release.json`, sustituya
`jobs/<job>.php` por `launcher/cron.php <job>.php`; no mezcle ambos modelos. La
pantalla Cron debe indicar el modelo instalado.

Si Hostinger no permite cada minuto, utilice la frecuencia mínima disponible.
El ERP mide el intervalo real y no supone que la frecuencia configurada se
cumple exactamente.

No configure `process_notifications.php`, `process_manual_queue.php`,
`process_manual_campaign.php` ni disparadores HTTP. Son adaptadores retirados
y no adquieren trabajo. Webhook-First está integrado en el lanzador único.

## Prioridad

1. OAuth y protecciones críticas.
2. Webhooks, notificaciones y órdenes nuevas.
3. Campañas dirigidas activas.
4. Envíos y enriquecimiento.
5. Preguntas, finanzas y reparaciones.
6. Auditorías.
7. Productos, descripciones y módulos.
8. Sincronizaciones recurrentes.
9. Mantenimiento.

Una campaña dirigida es una selección guardada con ritmo propio; no es otro
cron. El mismo orquestador la atiende después de las ventas urgentes y antes
del backlog histórico de baja prioridad.

## Cortes del hosting

El ERP no supone que PHP tendrá 50 segundos ni depende de
`shutdown_function`.

- Cada intento conserva reserva, salida, respuesta y último resultado aprobado.
- El checkpoint avanza solamente al aprobar el resultado.
- La siguiente ejecución detecta señales y leases vencidos.
- Una salida sin respuesta confirmada queda como `Resultado incierto`.
- Una respuesta recibida puede completar su fase local sin repetir transporte.
- La ventana segura observada baja después de interrupciones y aumenta
  lentamente tras cierres limpios.
- No se mantienen transacciones durante HTTP ni esperas.

Los intervalos en segundos se respetan dentro de una ejecución. Entre
ejecuciones puede existir una espera adicional impuesta por Hostinger.

## Diagnóstico

En `Configuración → Cron → Diagnóstico` se muestran:

- última señal CLI;
- intervalo real observado;
- cierre limpio o interrupción inferida;
- resultados inciertos;
- colas que no pudieron comprobarse;
- siguiente oportunidad estimada.

Una cola que no pudo leerse nunca se presenta como vacía.

## Seguridad

- Los jobs solo se ejecutan por CLI.
- Locks, leases y generación impiden dos workers sobre el mismo recurso.
- Las consultas comerciales se aíslan por empresa y `meli_account_id`.
- `ML_WRITE_ENABLED=false` permanece obligatorio.
- `GET /payments/{id}` permanece bloqueado.
