# Marketing: retirar tarjeta Gestión de Convocatorias

Único cambio de producción: eliminar 21 líneas del bloque `<section class="dashboard-panel marketing-expiring-panel">` en `app/views/dashboard/marketing.php`, sobre la base `7e27f0ae`. Incluye contenedor, título, descripción, separador y botón «Ir a convocatorias».

No se cambió CSS. Publicaciones recientes ocupa el espacio con su separación original de 24 px. Indicadores Clave, sus datos, estilos y enlaces permanecen intactos; también el resto de la vista, el modal oculto, consultas, permisos, menú lateral y rutas. El acceso lateral conserva `index.php?controller=convocatoria&action=index`; su controlador no se modifica. Otros perfiles y módulos quedan intactos.

Pruebas dirigidas: lint PHP; comparación del render real aislado antes/después en Chrome a 1517, 1280, 820 y 390 px; tarjeta y botón ausentes, HTML/datos/estilos/dimensiones de los demás widgets iguales y sin hueco ni desbordamiento añadido. Menú lateral renderizado conserva su texto y destino. Capturas locales: `tmp/marketing_sin_tarjeta/` (datos aislados, no producción). No se ejecutó CI global ni prueba de negocio en Hostinger.

Publicador: `tools/publicar_retirar_tarjeta_marketing_hostinger.sh`. SHA256: `5314bcf25d4e83780fdb29084960a9e7b4971695cb571a8904bd41282bbcd03e`.

Acepta sólo la vista conocida con el panel funcional existente. No sustituye vistas personalizadas; verifica sintaxis PHP y conserva permisos/finales de línea. Respaldo privado fuera de `public_html`. Pruebas del publicador en sitios temporales LF/CRLF: check sin escritura, apply de una vista, idempotencia, restore exacto y rechazo de ediciones antes/después; CSS, Indicadores Clave, otras vistas y configuraciones de prueba intactos.

Después de descargar el publicador revisado en `$HOME/impe-publicadores/retirar-tarjeta-marketing.sh` y verificar su SHA256:

```bash
export IMPE_CORREOS_PUBLIC="$HOME/domains/fundacionrededumex.org/public_html"
bash "$HOME/impe-publicadores/retirar-tarjeta-marketing.sh" --check
```

Con revisión compatible, ejecutar `bash "$HOME/impe-publicadores/retirar-tarjeta-marketing.sh" --apply`. Guardar el comando `--restore` con la ruta exacta que imprime. Recargar Inicio Marketing y comprobar también el acceso a Convocatorias desde el menú. Publicación manual pendiente: no hubo despliegue ni fusión automática. La rama conserva los avances previos del PR #136; revisar esta retirada por su commit específico.
