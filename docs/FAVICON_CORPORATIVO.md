# Favicon corporativo APG

El sistema usa **un solo archivo**: `public/favicon.ico` (símbolo APG).
El archivo incluye resoluciones 16x16 y 32x32 para las pestañas del navegador.

## Regla para cualquier vista nueva

- Si la vista utiliza `app/views/layout/dashboard_head.php`, **no agregues otra etiqueta**: el layout compartido incluye automáticamente el favicon.
- Si la vista tiene su propio `<head>`, agrega **dentro del head**:

```php
<?php require dirname(__DIR__) . '/layout/favicon.php'; ?>
```

La ruta relativa se aplica a páginas dentro de `app/views/<seccion>/`.
Para páginas PHP en la raíz usa `__DIR__ . '/app/views/layout/favicon.php'`.
El parcial utiliza `BASE_URL` cuando existe y, si no, obtiene el prefijo a partir de `SCRIPT_NAME`.
Versiona el enlace con la fecha de modificación del favicon para evitar caché obsoleta.

También existe una regla en `.htaccess` para que las solicitudes clásicas a `/favicon.ico` reciban `public/favicon.ico`.

## Verificar antes de desplegar

```bash
php tests/favicon_smoke.php
```

En Hostinger, el archivo final debe existir en `public_html/public/favicon.ico`.
No elimines ni sincronices con `--delete` las fotos de perfil, adjuntos, firmas ni otros datos generados por usuarios.
