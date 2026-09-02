# Migración 2.5

Ejecutar desde **Configuración → Actualizador** o CLI:

```bash
php bin/migrate.php
```

La migración nueva es:

- `029_sales_integrity_billing_safety_2_5.sql`

Agrega campos normalizados de fecha, tablas de diagnóstico/reparación y snapshots de seguridad para facturación.

No borra datos existentes.
