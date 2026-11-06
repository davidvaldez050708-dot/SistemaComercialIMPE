# Formularios: permisos y avisos

El acceso interno deja de depender del nombre Marketing. Roles y Permisos incorpora el grupo Formularios:

| Código | Operación |
|---|---|
| `formularios.ver` | Consultar módulo, Registro e Inscripción |
| `formularios.registro.capturar` | Guardar Registro interno |
| `formularios.inscripcion.capturar` | Guardar Inscripción interna |
| `formularios.compartir` | Obtener y compartir enlaces públicos |
| `formularios.comprobantes.ver` | Descargar comprobantes recibidos |

Los cuatro permisos operativos dependen de ver Formularios. La consulta sola deshabilita los campos y no muestra botones de guardado. El servidor comprueba los permisos independientemente del menú. Un nombre de rol Marketing sin permisos no autoriza operaciones.

Al abrir Roles y Permisos después de instalar se registra el catálogo mediante el mecanismo existente. Marketing recibe únicamente los permisos nuevos una vez para conservar su operación; una revocación posterior se conserva. Otros roles deben ser autorizados por el Administrador. Se respetan las políticas actuales de Administración y Dirección. Las sesiones deben refrescar sus permisos conforme al inicio de sesión existente.

Los enlaces públicos continúan sin sesión. Los permisos previos para acortar enlaces de Convocatorias y Aliados siguen válidos. No cambian campos, validación, horarios, almacenamiento, comprobantes, programas ni términos legales. Los errores de consentimiento adoptan la misma tipografía de los demás errores; los iconos de error no se superponen con fecha ni archivo.

## Publicación

`tools/publicar_formularios_permisos.py` y `tools/formularios_permisos_release.json` publican 11 archivos sobre las versiones exactas cotejadas con Hostinger. Las políticas y dependencias actuales se comprueban, no se sustituyen. Las copias de esas dependencias en Git sincronizan la fuente con lo ya instalado.

`--check` no escribe ni ejecuta SQL. `--apply` vuelve a comprobar versiones, valida sintaxis PHP, conserva un respaldo privado y aplica archivos atómicamente. Es idempotente. Rechaza personalizaciones desconocidas. Los archivos públicos quedan con permisos 644. Compatible con Python 3.6 y posteriores.

Para revertir, usar el mismo instalador y manifiesto: `--site "$SITIO" --backup-root "$RESPALDOS" --restore "/ruta/del/respaldo"`. La restauración comprueba que no existan modificaciones posteriores. Restaura archivos; no revoca el catálogo creado posteriormente por el Administrador.

## Validación

Las pruebas usan bases aisladas y datos sintéticos: matriz de roles, nueve rutas de servidor, dependencias, revocaciones persistentes, auditoría, menús de escritorio y móvil, consulta sin captura, enlaces públicos y errores uniformes. La prueba de publicación reconstruye las versiones exactas de Hostinger y comprueba check sin escrituras, apply, idempotencia, restore y rechazo de variantes. Se conserva la regresión de Inscripción y su publicación anterior como versión inmutable.
