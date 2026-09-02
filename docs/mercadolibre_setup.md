# Configuración Mercado Libre

Configure una aplicación para Colombia con:

- Redirect URI: `https://www.bodegadigitalmedellin.com/erp-meli/meli_callback.php`
- Webhook: `https://www.bodegadigitalmedellin.com/erp-meli/webhook_mercadolibre.php`

El `client_id` y `client_secret` pertenecen a la aplicación y viven en `config.env`. Cada cuenta autorizada tiene su propio `meli_user_id`, access token, refresh token, expiración y scopes cifrados.

Si no se ingresaron durante la instalación, el administrador debe abrir **Cuentas Meli**, guardar Client ID y Client Secret y copiar exactamente la URL de redirección mostrada en la configuración de su aplicación de Mercado Libre. El botón de conexión permanece deshabilitado mientras falte cualquiera de estos datos.

## OAuth

El administrador selecciona empresa y nombre interno. El ERP crea un `state` aleatorio válido por 10 minutos, redirige a Mercado Libre y consume el estado una sola vez en el callback. El refresh token se actualiza dentro de una transacción con bloqueo de fila para evitar dos renovaciones simultáneas.

## Revalidación obligatoria

Antes de habilitar producción revise la documentación oficial colombiana enlazada en `mercadolibre_api_map.md`. Confirme rutas, parámetros, límites, permisos y tópicos. Si hay divergencias, actualice primero el mapa, luego el cliente y sus pruebas.
