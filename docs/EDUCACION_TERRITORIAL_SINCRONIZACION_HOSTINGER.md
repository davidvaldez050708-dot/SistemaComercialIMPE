# Educación en Información Territorial · Hostinger

## Qué se corrigió

- El **rezago educativo oficial** (tabla MySQL) sigue sin cambiarse. Su vista
  ahora presenta tres cifras compactas, una evolución basada en los porcentajes
  históricos disponibles y la fuente en un pie discreto.
- El **contexto educativo general** no depende de volver a descargar un Censo
  histórico cada siete días. Los archivos JSON de formato completo que ya estén
  validados se pueden leer incluso cuando su fecha de escritura es antigua.
- Los archivos incompletos o inválidos no se presentan como datos oficiales.
- Si aún no existe un contexto local compatible, un usuario con permiso
  `data_territorial.actualizar_oficial` puede sincronizarlo desde
  **Información Territorial → Actualizar información oficial →
  Contexto educativo general**, por un Estado o mediante el modal masivo.
- El servidor usa INEGI; errores de red o permisos de `storage/cache/inegi`
  deben comunicarse como incidencia de sincronización, nunca como cifra cero.
- Las consultas de analistas no inician descargas; el endpoint público
  `public/inegi_educacion_objetivo.php` permanece exclusivamente en lectura.

## Archivos que se publican en producción

Desde `~/crm-preparacion/` se actualizan estos cinco archivos en
`~/domains/fundacionrededumex.org/public_html/`:

- `app/controllers/DataTerritorialController.php`
- `app/services/InegiEducacionObjetivoService.php`
- `app/views/data_territorial/index.php`
- `public/javascript/educacion_objetivo.js`
- `public/css/educacion_objetivo.css`

No copiar ni sobreescribir `config/*.local.php`,
`../impe-private`, `storage` ni importar SQL.

## Validación

```bash
php -l app/controllers/DataTerritorialController.php
php -l app/services/InegiEducacionObjetivoService.php
php -l app/views/data_territorial/index.php
node --check public/javascript/educacion_objetivo.js
php tests/territorial_educacion_smoke.php
```

La prueba de caché utiliza una carpeta temporal aislada y no consulta la base
de datos ni APIs externas.

## Prueba manual en Hostinger

1. Iniciar sesión con un rol autorizado y abrir Información Territorial de un Estado.
2. Educación → Contexto educativo adicional → Rezago educativo oficial:
   los valores, referencia nacional, histórico y fuente deben conservarse.
3. Si el contexto general está pendiente, seleccionar únicamente
   **Contexto educativo general** en Actualizar información oficial.
   La operación puede tardar si INEGI necesita descargar un archivo.
4. Tras completar la operación, refrescar Educación. Deben aparecer
   Indicadores generales del Censo y Contexto juvenil si la fuente respondió.
5. Revisar un Estado sin caché: la pantalla debe indicar que la información
   todavía no está guardada, sin fingir sincronización.

Si INEGI no responde, la operación informará una incidencia y el último dato
válido del servidor permanecerá disponible.
