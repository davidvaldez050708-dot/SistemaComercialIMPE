# Bachillerato General en Convocatorias

PR #124; corrección `6f4ca253d8725f1db77a6335a63b105d517f3a62`, sobre la entrega conjunta `1a87de2caa779309c613972fe9495ff76c9b5a03` ya instalada.

El catálogo de Registro contiene `bachillerato-general`, pero su correspondencia estaba ausente en `AliadoRegistroService::clasificacionesPorPrograma()` y el controlador tampoco aceptaba ese subtipo. Ahora General tiene `bachillerato|bachillerato-general`: no se convierte en 286 ni se modifica la oferta del aliado. Lo usan todos los aliados manuales y de Vinculación autorizados, incluidos los nuevos. Listado y filtro de reportes reconocen el subtipo nativo. El mensaje de error utiliza la clase institucional `login-alert` existente; no se cambia el diseño aprobado del editor.

Además, un programa activo desconocido conserva una opción sin clasificación, para que V3 y el backend bloqueen el lote completo: no se omite silenciosamente un programa y no se inventa su equivalencia. Añadir un programa futuro distinto al catálogo exige definir su clasificación explícita.

## Entrega selectiva

`tools/convocatorias_general_release.json`, con el publicador existente `tools/publicar_aliados_registro.py`, incluye exclusivamente:

- `app/services/AliadoRegistroService.php`.
- `app/controllers/ConvocatoriaController.php`.
- `app/views/convocatorias/index.php` (opción de clasificación, sin sustituir el modal modular).
- `app/services/ReporteConvocatoriaDataService.php`.
- `public/javascript/convocatorias_aliado_multiprograma_v3.js`.

No requiere SQL ni cambios en programas, enlaces o registros productivos. Conservar la versión por SHA256 del componente modular ya instalada; actualizará la URL del JS al cambiar su contenido.

Descargar revisión inmutable e integridad indicadas en la conversación, ejecutar `--check`, revisar diferencias y sólo después `--apply`. Un conflicto detiene toda la entrega; no reemplazar el index ni el controlador completo. Repetir `--check` después: cero pendientes.

## Validación

Se probaron General en manual y Vinculación, Marketing y Cuenta Clave; General junto a 286 con códigos independientes; guardado HTTP/BD real en esquema sintético; programas y clasificaciones manipulados; permisos, territorio, rollback y catálogo existente. SSP conserva el mismo diseño mediante comparación de imágenes. El publicador conserva un index convertido a las inclusiones modulares y admite check, apply, idempotencia y restauración, también CRLF.

Tras publicar, abrir IQJ y confirmar una tarjeta Bachillerato General, sin aviso de incompatibilidad, y Guardar convocatorias habilitado. Verificar que siga mostrando únicamente los programas realmente autorizados. El guardado conserva la clasificación General y su procedencia.

## Restaurar sólo si se decide deshacer esta corrección

Guardar la ruta exacta de `Respaldo:`. Este comando no forma parte de la instalación:

```bash
RESPALDO="/ruta/real/del/respaldo/de/esta/entrega"
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-general" --restore "$RESPALDO"
```

No ejecutar con la ruta de ejemplo. La restauración comprueba ediciones posteriores y no modifica la BD.
