# Puente de lectura: Zoho Leads → Prospectos

`tools/importar_zoho_leads.php` consulta `Leads` en la organización Zoho CRM **Developer** configurada y agrega al almacén central únicamente IDs que aún no estén registrados como `ZOHO_CRM/LEAD`. No cambia formularios, asignaciones, campañas, llamadas ni registros anteriores. Los Leads aparecen en la pestaña **Todos los prospectos** con procedencia **Zoho CRM**. Por compatibilidad con el catálogo actual, se guardan como `FORMULARIO_GENERAL`; por ello también se cuentan en ese origen.

## Configuración privada

Si ya existe `zoho_sync.local.php` del piloto, el puente reutiliza únicamente su `expected_org_id` y no necesita otro archivo. Como alternativa, crear `zoho_leads.local.php` en `domains/fundacionrededumex.org/impe-private/` (fuera de `public_html`):

```php
<?php
return [
    'enabled' => true,
    'expected_org_id' => 'ID_NUMERICO_DE_LA_ORGANIZACION_DEVELOPER',
];
```

Se reutiliza `$HOME/.config/sistema_comercial_impe/zoho_oauth.json` del piloto existente. El refresh token necesita el permiso de lectura `ZohoCRM.modules.leads.READ` y acceso a la organización esperada. No se guardan credenciales ni respuestas JSON de Zoho en el repositorio o la base de datos.

```sh
php tools/importar_zoho_leads.php --check
php tools/importar_zoho_leads.php --apply
```

`--check` consulta Zoho y la base de datos sin escribir. `--apply` incorpora nuevos Leads de forma transaccional y repetible. La clave única del origen evita volver a incorporarlos. Un archivo `flock` privado impide ejecuciones superpuestas. La importación **no sincroniza ediciones ni bajas** de Zoho, ni fusiona por teléfono o correo. Se limita a los primeros 2000 Leads y se detiene si hay más para evitar una importación parcial silenciosa.

Programar una tarea cron solo después de comprobar el primer resultado y la autorización de lectura. Ejemplo: cada 15 minutos, con salida redirigida a un log privado. La pantalla de Prospectos no realiza solicitudes a Zoho al abrirse.

`tools/publicar_zoho_leads.py --check` verifica rutas, huellas y sintaxis sin tocar el sitio. `--apply --backup-root ...` instala únicamente los dos archivos nuevos. Si alguno ya existe y difiere, se detiene y conserva la personalización; el registro privado enumera los archivos que agregó para retirarlos si se requiere restaurar esta publicación.
