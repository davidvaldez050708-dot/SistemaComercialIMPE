# Convenios y editor V3: integración selectiva

PR #124, rama `feat/aliados-programas-registro`. No ejecutar SQL ni desplegar el repositorio completo.

## Evidencia

Los hashes comunicados por SSH coinciden con los recursos **anteriores** de Convenios: CSS `9368dda2…`, JavaScript principal personalizado `7095c767…` y extensión `1c3a2514…`. El V3 actualizado sí existe (`5e0183eb…`), pero el componente PHP conserva URLs fijas `?v=1`/`?v=3`. Hostinger anuncia `Cache-Control: public, max-age=604800`. El archivo nuevo en disco no invalida esas URLs en un navegador que conserve una copia anterior. No se ha determinado quién o qué restauró los recursos de Convenios; no atribuirlo sólo a caché.

## Entrega

`tools/convenios_v3_integracion_release.json` reúne los cinco destinos de Etapa 3, el V3 ya corregido y una modificación puntual del componente existente. El publicador `publicar_convenios_v3_20261022.py` reutiliza `publicar_aliados_registro.py`; ambos deben estar en la misma carpeta privada de preparación.

En `aliado_programas_modular.php` sólo se versionan cuatro URLs mediante el SHA256 real del recurso. Se conservan HTML, clases, opciones, contexto, enlaces, QR y guardado. No se sustituye `crear_modal.php`, el controlador ni el layout. Si el componente o alguna referencia difieren de las anclas revisadas, se detiene antes de escribir. También se exige que existan las cuatro dependencias. Las otras personalizaciones se concilian mediante el publicador existente, incluida la variante internacional exacta del JavaScript principal.

Usar los comandos con revisión inmutable e integridad SHA entregados en la conversación. Ejecutar `--check`, revisar diferencias y sólo entonces `--apply`. En el estado diagnosticado se esperan seis archivos pendientes; V3 debe aparecer `SIN CAMBIOS`. Después de aplicar, repetir `--check`: debe indicar cero pendientes. No ejecutar `--restore` como paso de instalación.

## Comprobación funcional

Recargar Convenios: filas compactas, sin hover de fila y botón Ver detalle. Revisar manual y Vinculación, origen compacto, notas íntegras, chips y filtros. Desde cada origen, el editor debe mostrar PUBLICACIONES POR PROGRAMA y Guardar convocatorias, con una tarjeta por programa autorizado. Si faltan clasificaciones, debe mostrar un estado institucional y bloquear el guardado individual; no inventar programas.

En pruebas se usa la plantilla existente, los recursos reales y una base sintética. Se simula una copia antigua ligada a `v=3`; el cambio por contenido carga V3 actual sin cambiar el diseño aprobado. Se verifican persistencia multiprograma, rollback, roles y territorio. Estas pruebas no consultan los programas configurados realmente para IQJ en producción ni sustituyen su comprobación funcional posterior.

## Restauración opcional, sólo para deshacer esta entrega

Guardar la ruta exacta de `PUBLICADO. Respaldo:`. Conservar la carpeta de preparación usada para instalar. Sólo si se decide revertir:

```bash
RESPALDO="/ruta/real/del/respaldo/de/esta/entrega"
python3 "$PREP/publicar.py" --site "$SITIO" \
 --manifest "$PREP/entrega.json" \
 --backup-root "$HOME/respaldos-impe-convenios-v3" --restore "$RESPALDO"
```

La restauración comprueba que no hubo cambios posteriores, repone los bytes originales y conserva la base de datos. No ejecutar el ejemplo con la ruta sin sustituir.
