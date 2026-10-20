# Oficios REDMEX: bloqueo de conversión en Hostinger

Fecha: 20 de octubre de 2026. Rama: `fix/ventas-etapa2-audio-operacion`.
Estado: **diagnóstico confirmado; generación y vista previa pendientes de restaurar en producción**.

## Evidencia del servidor

Diagnóstico SSH proporcionado por la usuaria:

```text
PHP 8.2.34 SAPI cli
proc_open=NO
disable_functions=system, exec, shell_exec, passthru, mysql_list_dbs, ini_alter, dl, symlink, link, chgrp, leak, popen, apache_child_terminate, virtual, mb_send_mail, proc_open
command -v libreoffice: sin salida
command -v soffice: sin salida
```

Esto acredita que el PHP CLI consultado no dispone de `proc_open` y que esos
ejecutables no están en el PATH de SSH. No acredita ausencia absoluta de un
ejecutable en otras rutas ni que CLI y PHP web compartan configuración.
El mensaje mostrado por la vista previa sí coincide con el retorno explícito
de `OficioDocxPdfService::generarPdf()` cuando PHP web no tiene `proc_open`.

No se ha probado una conversión satisfactoria en Hostinger. No se han cambiado
funciones deshabilitadas, configuración del hosting ni datos de producción.

## Origen en código e historial

- `app/services/OficioDocxPdfService.php`: prepara el DOCX y convierte mediante
  LibreOffice, Word en Windows o PHPWord/Dompdf. `generarPdf()` exige
  incondicionalmente `proc_open` antes de seleccionar el conversor.
- `public/oficio_vista_previa_documento.php`: prepara la vista y llama a ese
  servicio; devuelve el error como texto con HTTP 500, que aparece en el modal.
- `app/services/OficioPdfService.php`: reutiliza un PDF previamente asociado
  cuando existe. En una conversión nueva fallida retorna antes de guardar el
  PDF y antes del UPDATE que establece `GENERADO`.
- `public/oficio_pdf_documento.php`: entrega el archivo previamente registrado;
  descargarlo no requiere una nueva conversión.
- `3eef5065` (07/09/2026) introdujo el servicio basado en la plantilla DOCX.
- `dfa1c6a8` (21/09/2026) añadió el respaldo PHPWord/Dompdf, conservando la
  exigencia previa de `proc_open`. Por ello ese respaldo no resulta alcanzable
  desde la generación normal en un entorno que deshabilite dicha función.

El historial explica la dependencia actual y la inconsistencia del respaldo.
No identifica la fecha en que producción dejó de funcionar ni demuestra qué
configuración o versión produjo el último PDF correcto en ese servidor.
La implementación anterior con HTML/Dompdf no reproduce necesariamente las
plantillas DOCX seleccionadas; restaurarla sin más incumpliría la fidelidad exigida.

`convertirArchivoAPdf()` también es utilizado por Convenios. No se ha alterado
ese método, sus consumidores ni ningún otro módulo.

## Pruebas dirigidas realizadas

Se usaron datos ficticios y copias temporales; la plantilla original no se modificó.

| Comprobación | Resultado y límite |
| --- | --- |
| DOCX desde plantilla general | Generación local satisfactoria conservando como entrada el folio `REDMEX/0270/17-10/26`. Sin acceso a la BD. |
| Word instalado en el equipo local | PDF válido de una página carta (612 × 792 puntos), con encabezado, logotipos, pie y elementos institucionales visibles en la revisión de la imagen renderizada. No certifica todas las plantillas personalizadas. |
| PHPWord/Dompdf con DOCX original | Falla al leer comentarios: `Comment with id 0 isn't referenced in document`. |
| PHPWord/Dompdf tras vaciar comentarios únicamente en una copia temporal de prueba | Produce un PDF de dos páginas A4 (595,28 × 841,89 puntos), pierde el encabezado y desplaza el pie. Alternativa descartada para esta plantilla. |
| `generarPdf()` con `php -d disable_functions=proc_open` | Reproduce el mensaje reportado; no crea PDF ni temporales nuevos. Entrada de folio conservada. Prueba sin conexión a BD, no una prueba transaccional de estados. |
| Folios y estados en código | El error del conversor retorna antes de persistir el archivo y marcar `GENERADO`. Inspección dirigida, sin modificar registros. |
| Vista previa y descarga por HTTP autenticado en producción | Pendientes; no se presentan como satisfactorias. |
| Regresión de otros módulos | Sin cambios de aplicación ni pruebas globales. |

La inspección visual y las dimensiones se comprobaron con PDFs locales
renderizados y `pdfinfo`. Los PDF de ensayo, imágenes y scripts exploratorios
permanecen en `tmp/`, fuera de esta entrega y del despliegue.

## Decisión y alternativa mínima

No basta con retirar el bloqueo de `proc_open`: además de la excepción del
lector PHP, el PDF obtenido pierde la presentación REDMEX. Tampoco basta con
habilitar una función si no se dispone de un conversor instalado y ejecutable.
No se propone modificar los controles de seguridad de Hostinger.

Para mantener la plantilla hace falta disponer de un motor que la renderice
correctamente. La alternativa compatible con conservar las restricciones del
hosting es ejecutar la conversión fuera de PHP compartido, en un equipo propio
con Word o en un servidor propio con LibreOffice, y comunicarlo por HTTPS.
La disponibilidad de ese equipo es un requisito pendiente, no una infraestructura
existente asumida. LibreOffice también necesitaría comparación visual antes de
aceptarse para cada plantilla; sólo Word se probó en este diagnóstico.

Antes de implementar esa alternativa deberá concretarse el equipo disponible.
La integración debe limitarse a los oficios, autenticar la comunicación, vincular
cada resultado al oficio/folio y al DOCX solicitado, validar el PDF recibido y
conservar el estado ante cualquier fallo. No debe publicar un endpoint abierto
ni enviar documentos a servicios ajenos sin autorización.

Como contingencia manual puede usarse la descarga DOCX ya implementada en
`OficioVinculacionController::descargarDocx()` y exportarlo con Word, verificando
el formato. Esa operación por sí sola **no** incorpora el PDF al CRM, no restaura
el modal ni completa el paso 6. No se ha añadido una carga de PDF ni un flujo nuevo.

## Entrega y validaciones pendientes

Esta entrega contiene sólo este diagnóstico. No modifica PHP, JavaScript,
CSS, plantillas, folios, permisos, estados ni configuraciones. No requiere un
publicador de producción: no hay corrección ejecutable que aplicar o restaurar.
El publicador de telefonía no corresponde a esta tarea.

Una vez disponible y validado el motor, quedan pendientes las pruebas A–G del
pedido: vista previa autenticada, generación desde borrador, descarga del oficio
correcto, conservación del folio, fidelidad de la plantilla seleccionada, fallo
sin cambios de estado y continuidad de las acciones de oficios. La corrección
ejecutable requerirá entonces un publicador selectivo `--check/--apply/--restore`
y validación real en Hostinger, sin despliegue automático ni cambios en la BD.
