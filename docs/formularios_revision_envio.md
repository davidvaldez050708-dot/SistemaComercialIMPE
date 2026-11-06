# Revisión antes de enviar

Registro público, Registro en CRM e Inscripción comparten un aviso previo y un modal de confirmación con el logo institucional. Incluye nombre, fecha de nacimiento, correo y móvil; Inscripción muestra también CURP. No consulta RENAPO ni modifica la validación del servidor.

El aviso previo se presenta como texto de ayuda, sin recuadro, fondo ni franja lateral. El ajuste sobre una instalación previa de PR226 modifica únicamente la hoja CSS; los cuatro JavaScript quedan intactos.

Los campos deben pasar las validaciones del navegador existentes antes de abrir el modal. Revisar datos, Escape, la X o pulsar fuera cancelan la confirmación, sin borrar datos ni archivos. Sólo Confirmar y enviar ejecuta el POST existente. El servidor conserva su validación completa, CSRF, autorización, duplicados y almacenamiento. Un bloqueo evita abrir confirmaciones simultáneas y el doble envío. Si los recursos de revisión no cargan, no se envía silenciosamente.

El resumen usa textContent; no interpreta HTML capturado. Hay control de foco, navegación por teclado, movimiento reducido y diseño móvil. No añade casillas ni cambia textos legales.

El paquete sellado `tools/formularios_revision_envio_release.json` modifica tres JavaScript cotejados con Hostinger y añade un JavaScript y una hoja CSS. No sustituye vistas PHP, controladores, permisos, tablas ni registros. Los assets compartidos se cargan desde los scripts existentes para conservar las variantes locales de las vistas.

`tools/publicar_formularios_revision_envio.py` admite --check, --apply y --restore. Comprueba las versiones exactas, conserva respaldo privado, aplica atómicamente y deja archivos públicos con permisos 644. No ejecuta SQL. Compatible con Python 3.6 y posteriores. --restore requiere la ruta completa del respaldo y rechaza modificaciones posteriores.

Pruebas: formulario público, Registro CRM, Inscripción actual y anterior; campos inválidos sin modal, confirmación sin POST previo, cancelación conservando datos, logo, teclado, móvil, resumen seguro y envío único. Regresión de Inscripción con datos sintéticos en base aislada. El fixture declara utf8mb4 para leer JSON con acentos de forma consistente.
