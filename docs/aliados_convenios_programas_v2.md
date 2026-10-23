# Aliados y Convenios — refinamiento visual y convocatorias por programa

## Estado de implementación
Trabajo en la rama del PR #124. **No está desplegado en Hostinger.** El publicador anterior `tools/publicar_aliados_registro.py` utiliza un manifiesto inmutable de la primera entrega y NO incluye estos cambios; tampoco el publicador selectivo de los checkboxes cubre esta ampliación. No ejecutar ninguno de ellos para intentar instalar esta versión.

## Experiencia
- Aliados → Estado: desglose visual entre formalizados y manuales, botones compactos con ancho suficiente, menú de tres puntos para aliados manuales. Los manuales pueden editarse, administrar varios contactos y consultar/registrar un historial de difusiones realizadas; **no se les atribuyen expedientes, grabaciones, transferencias, envíos ni seguimientos de Vinculación**.
- Altas, ediciones, programas, desactivación y contactos utilizan los `system-toast` de Bootstrap del CRM; los errores se muestran además en el modal.
- Marketing → Convenios: primero selector visual de estados (mapas existentes, búsqueda, filtro con/sin convenio); luego directorio del estado en tabla sin iconos repetidos, agrupado por municipio y filtrable por municipio, origen y búsqueda. La información de cada convenio conserva el modal.
- Se retiró el generador de "Formulario de Registro exclusivo" del modal de Convenios. **Crear convocatoria** abre el modal existente de Gestión de Convocatorias para ambos tipos de aliado y carga solo los programas autorizados.
- Cada convocatoria representa **un programa, una imagen, un título, fechas y clasificación**, por lo que varias imágenes significan varias filas de `convocatorias`, y aparecen en los apartados ya existentes de Bachillerato/Titulación.
- El enlace y QR de Registro del aliado **son únicos y estables, compartidos entre todas sus convocatorias**, independientemente de la imagen/programa. El público aún elige cualquiera de los programas autorizados. El QR se produce localmente con `qrcode-generator`, sin enviar tokens a QuickChart. El enlace se guarda en `convocatorias.enlace_registro`.
- La clasificación se mapea de forma explícita en PHP; `Bachillerato General` **no equivale** a `Bachillerato 286`.

## Estructura y compatibilidad
Migraciones aditivas nuevas:
1. `database/migrations/2026_10_22_aliados_manuales_contactos_difusion.sql`: contactos adicionales y difusiones declaradas de origen manual.
2. `database/migrations/2026_10_22_marketing_convocatorias_aliados_manuales.sql`: vincula cada convocatoria a un aliado manual y programa sin expedientes ficticios.

La gestión de publicaciones institucionales continúa utilizando las tablas y seguimiento originales. Los datos de registro de prospectos permanecen en `formulario_registros`, con procedencias `formulario_aliado_procedencias`; Inscripción, Handly y telefonía no deben modificarse.

## Validación y publicación
- Las pruebas aisladas de las dos nuevas tablas y contactos figuran en `tests/aliados_registro_db.php`. El recorrido navegador incluye selector territorial y tabla municipal. El workflow `aliados-registro.yml` prueba MySQL 8 / MariaDB 10.11 además de regresiones separadas de PR114/Handly. **Consultar resultado de CI antes de aprobar.**
- Para una revisión visual real comprobar 1440, 1366, 844×390 y 390×844; especial atención a scroll, footer de modales, íconos, anchura de acciones, QR y errores de consola.
- En producción es indispensable **preparar un nuevo paquete selectivo revisado** que concilie los archivos modificados con las personalizaciones Hostinger y verifique SHA, PHP/JS, respaldo/restauración. No reutilizar el manifiesto anterior ni reemplazar indiscriminadamente `public_html`.
- Después de verificar respaldo SQL completo del sitio y compatibilidad del paquete, el script CLI `tools/migrar_extensiones_aliados_20261022.php --check` comprueba dependencias sin escribir. `--apply --backup-dir=RUTA_PRIVADA` instala solo las dos migraciones nuevas con respaldo privado, y `--check` verifica la estructura. No ejecutar `--apply` antes de terminar la revisión del PR y el paquete.
- No abrir enlaces de prueba con datos reales hasta completar estas comprobaciones. Conservar tokens existentes y nunca publicar respaldos.
