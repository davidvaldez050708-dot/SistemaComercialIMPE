# Ecard y notificaciones de nuevos aliados

La Ecard podía usar imagestring de GD (tamaño fijo) al no encontrar Arial o las rutas Linux de DejaVu. Se usan primero las fuentes TrueType que ya distribuye la dependencia Dompdf del proyecto y se verifica su carga. Se conserva la composición aprobada, los tamaños previstos, las fotografías y los datos; un ajuste vertical mantiene los nombres largos dentro de su zona. La versión de caché cambia para regenerar la Ecard en la siguiente vista previa/generación. No modifica imágenes ya enviadas ni envía correos. Si falta FreeType o una fuente válida, informa el problema en lugar de producir otra imagen con letra diminuta.

La campana sólo consultaba seguimientos de convocatorias, sin una fuente para aliados recién formalizados. AliadoNuevoNotificationService consulta asignaciones activas de aliados con seguimiento activo y convenio formalizado. El destinatario es exclusivamente cuenta_clave_usuario_id de esa asignación; no se infiere a partir de un rol compartido. El acceso al centro requiere aliados.ver, igual que el destino. Administrador conserva su centro propio. No se crean ni reasignan aliados.

Cada pendiente lleva su enlace al territorio autorizado y la identificación del aliado. Abrir el aviso marca únicamente esa asignación del destinatario después de validar el territorio y comprobar que el aliado esté en su listado. El servicio vuelve a validar propiedad/actividad al guardar la lectura. La lectura se conserva en aliados_novedades_lecturas por usuario, asignación, fecha de asignación y formalización. Una nueva versión puede avisar de nuevo. Al desactivar o reasignar, desaparece del destinatario anterior. Los aliados existentes sin lectura también aparecen; no se inventa una fecha de alta ni se ocultan pendientes anteriores automáticamente.

El toast reutiliza recordatorios.js y recordatorios.css sin cambios: duración 7 segundos, consulta 60 segundos y turno compartido cada 8 minutos, incluida la espera inicial. Usa exactamente la misma clave del pendiente para persistir al navegar y descartarse al leer/desasignar. Se entrega una vez por usuario/sesión/versión; sólo se registra como entregado si el pendiente pasa el límite global de 12 elementos de la campana, para no perderlo detrás de urgencias. Se mantienen los límites existentes, sin saturar la pantalla de avisos simultáneos.

## Aplicación en Hostinger

Publicador: tools/publicar_ecard_aliados_hostinger.sh, modos --check, --apply y --restore RUTA. SHA256: d67de22ecc11ec18837f6db92b480ce72eba8bb4c5c874a738d846369d414ac1.

Comprueba FreeType y las dos fuentes de Dompdf antes de instalar. Publica cuatro parches de contexto exacto y dos archivos nuevos; preserva contenido exterior y rechaza bloques personalizados incompatibles. Crea respaldo privado y muestra el comando de restauración. No ejecuta SQL, conexiones externas, confirmaciones ni correos.

Después de --apply, ejecutar por SSH:

```bash
php "$HOME/domains/fundacionrededumex.org/public_html/tools/configurar_avisos_aliados.php" --apply
```

Este segundo comando crea únicamente la tabla de lecturas si falta y comprueba su clave. Es idempotente y no altera reuniones, aliados, asignaciones o credenciales. El modo --check sólo consulta el esquema. No se crea la tabla desde peticiones web. Restaurar código conserva las lecturas; no elimina tablas automáticamente. Si falta el esquema, esta fuente informa estado parcial y se conservan las demás notificaciones.

Recargar el CRM, volver a abrir la vista previa de Ecard y comprobar la campana con Cuenta Clave. Abrir un aviso debe llevar a su territorio y consumir sólo ese aviso; el toast usa su turno de 8 minutos. Pendiente validación en Hostinger después de aplicar; no se hicieron pruebas con reuniones o aliados reales.

## Validación

- tests/ecard_aliados_test.php: SQL real en SQLite aislado con adaptación de SHOW TABLES y ON DUPLICATE KEY; propiedad, actividad, formalización, aislamiento de usuarios, lectura durable/idempotente, cambio de versión, reasignación, esquema ausente y cola no consumida detrás del límite real del controlador. Reproduce el fallo anterior sin fuentes del sistema; genera ambas plantillas con nombres cortos/largos y comprueba que un bloque de tres líneas no invada fecha/hora. Inspección visual de las imágenes generadas.
- tests/ecard_aliados_browser.cjs: cuatro escenarios con el presentador real, Bootstrap y fallback, escape HTML, campana, navegación 4+4 minutos, duración 7 segundos, sondeo de 60 s, descarte al resolver y enlace al territorio.
- Regresión de agenda PHP y sus ocho escenarios de navegador; once escenarios existentes del centro de toast. Total: 23 escenarios de navegador aprobados, sin producción.
- tests/ecard_aliados_instalador_test.py: instalador aditivo sobre SQL aislado, ausencia de esquema, apply/check e idempotencia. No carga la configuración real.
- tests/ecard_aliados_publicador_test.py: sitios ficticios LF/CRLF, check sin escritura, aplicación exacta, preservación de personalizaciones exteriores, respaldo/restauración, eliminación de archivos nuevos al restaurar, actualización parcial y rechazo de bloques/archivos ajenos. Se comprueba que agenda, formalización, JavaScript, CSS y configuración permanecen intactos.
