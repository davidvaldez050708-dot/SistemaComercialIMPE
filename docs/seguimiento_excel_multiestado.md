# Importación de Seguimiento por estado de cada fila

## Regla

La columna Estado de la hoja Instituciones determina el destino de cada fila. Se puede mezclar Morelos, Michoacán y Estado de México en un mismo XLSX. El modal ya no solicita un estado único. Muestra el estado en la revisión y conserva los estilos, selección de archivo, confirmación y mensajes de la importación.

Un analista importa para sí mismo dentro de sus asignaciones. Administración o supervisión seleccionan un responsable entre los analistas elegibles en sus territorios; cada fila exige la intersección entre acceso del usuario y asignación vigente del responsable. No asigna automáticamente otro analista ni amplía el alcance. Al confirmar se recalcula el alcance; si una fila nueva quedó fuera, se revierte toda la transacción. Las revisiones de la versión anterior caducan para impedir reutilizar un token con reglas distintas.

## Plantilla y requisitos conservados

19 columnas, encabezados compatibles con V3. Hasta 250 instituciones/2 MB. Son obligatorias B–N: nombre, tipo, estado, municipio, actividad/giro, dirección, teléfono, WhatsApp, correo institucional, sitio web, persona de contacto, cargo/área y paso histórico. Son opcionales A y O–S: referencia, fecha última gestión, evidencia, próxima acción, fecha próxima acción y observaciones. Las opciones 00–13 siguen siendo antecedentes declarados: no generan llamadas, verificación, actividad o convenios reales. No se flexibilizan datos ni se inventan contactos.

La plantilla adjunta por el usuario contiene los encabezados esperados y ninguna institución. Su guía es anterior al importador y todavía habla de una implementación futura. Se conserva como fixture de lectura. La descarga generada por el CRM ofrece instrucciones vigentes para archivos multiestado, sin cambiar columnas.

## Compatibilidad y publicación

Los seis hashes suministrados identificaron cinco archivos exactos y una variante local del controlador. La variante se reconstruyó sobre dfb170bc con los cambios comparados y coincide con b2aa24d1c73f0ab47f409957fcc9bd58e0e3251845c915c4a015bd80c01c7b83. Commit e3670d3f sincroniza ese controlador; mantiene las correcciones telefónicas y de acceso territorial instaladas.

Paquete selectivo de seis archivos: controlador, importador, generador de plantilla, vista del índice, JavaScript y CSS de importación. Publicador compatible con Python 3.6.8, SHA exactos y manifiesto sellado, respaldo privado, archivos públicos 644 y restauración protegida. No ejecuta SQL ni importa datos durante --check/--apply. No modifica el modelo, telefonía, Aliados, Campañas ni expedientes existentes. No desplegar la rama completa.

## Pruebas

- Multipart real con la plantilla adjunta: formato compatible, vacío detectado; copia sintética con tres estados leída sin base de datos.
- MariaDB aislada con importador y método real de persistencia: resolución con/sin acentos, 13 obligatorios, seis opcionales vacíos, municipio/estado, autorización, rollback, guardado en los tres estados, detección de duplicados al analizar y al confirmar. Se crea y destruye una BD temporal en puerto explícito distinto de 3306.
- Helper real del controlador con catálogo simulado: alcance propio, global, equipo, responsable inválido y asignación revocada.
- Formulario PHP, CSS y JS reales con transporte simulado en Chromium y WebKit: multipart íntegro, ausencia del estado global, responsable, columna Estado, CSRF, tokens, errores y diseño móvil/escritorio.
- Paquete en réplica temporal: check sin escritura, versiones desconocidas, integridad, idempotencia, respaldo y restore. Python 3.6.8 y moderno.

## Comprobación tras instalar

Abrir el modal y verificar que no existe el selector de estado. Descargar una plantilla nueva y consultar «¿Qué campos debo llenar?». Analizar un archivo de prueba autorizado de varios estados y revisar el estado por fila, sin confirmar datos ficticios en producción. Confirmar únicamente un archivo real autorizado después de revisar las filas nuevas. El botón de confirmación sigue separado de la lectura.

Para revertir: mismo publicador/manifiesto, --site y --backup-root, añadiendo --restore /ruta/respaldo. Rechaza cambios posteriores a la instalación.
