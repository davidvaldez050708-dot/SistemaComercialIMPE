# Atención telefónica del analista · 17 de octubre de 2026

Una llamada efectiva acredita una verificación de datos institucionales respaldada
por una conversación confirmada. Contestar por sí solo no acredita la meta. Se
conserva como máximo un crédito por institución, analista y día, aunque el
historial contenga varias conversaciones útiles.

## Flujo que se entrega

1. **Durante la conversación:** Datos de llamada conserva institución, persona
   que atendió, teléfono, correo, contacto referido, cargo y notas. Autoguarda por
   usuario y referencia de llamada en el navegador y en el servidor. Si falla la
   red informa que sigue pendiente la copia del servidor. El borrador recuperable
   vence a las 24 horas; los vencidos se depuran al siguiente guardado del usuario.
2. **Después de una transferencia:** Registrar resultado permite elegir un
   expediente propio, crear una institución en territorio asignado o conservar
   la llamada sin identificar. La persona que atendió se distingue de la persona
   o área que proporcionó como contacto. Los datos referidos actualizan la ficha
   y quedan como evidencia por campo, con su valor anterior e informante.
3. **Verificación:** se marcan solamente los datos confirmados. Una verificación
   parcial no completa toda la ficha ni regresa la etapa comercial. Se conserva
   una devolución futura ya programada cuando no se sustituye explícitamente.
4. **Confirmación tardía de PBX:** una sesión WebRTC guarda la atención y su
   evidencia pendiente. Cuando el historial puede conciliarla con una conversación
   de la extensión propia confirmada por PBX, ofrece Confirmar verificación.
   Esa acción acredita la interacción existente, sin crear otra atención.
   Los empates de coincidencia se dejan separados para revisión.
5. **Llamadas salientes institucionales:** Datos de llamada se recupera en el
   formulario del resultado por referencia exacta, sin reemplazar campos ya
   escritos. La evidencia se guarda pendiente y se confirma o rechaza cuando el
   enlace técnico existente consulta la respuesta de Zadarma.
6. **Resultados negativos:** buzón, fuera de servicio, ocupado, número incorrecto
   y sin respuesta no generan verificación. Un resultado negativo no puede crear
   una institución nueva. Las llamadas internas no se vinculan a expedientes.

## Modales y diseño

Se reutilizan el modal, botones, campos y toast del sistema. El encabezado y el
pie permanecen visibles; sólo se desplaza el cuerpo con rueda, gesto o teclado.
Los desplegables usan los controles de Seguimiento. Un error conserva el
formulario. Mientras se guarda se bloquean el doble envío y el cierre. Al cancelar
se devuelve el foco al botón que abrió el modal. La navegación posterior espera
a que termine la animación de cierre. Una recuperación tardía del borrador no
reemplaza lo que el analista acaba de escribir.

Los resultados definitivos y la exclusión de grabaciones de Recepción se
mantienen. Este cambio no modifica la grabación contratada con Zadarma.

## Instalación en Hostinger

Publicar juntos los archivos PHP y JavaScript de esta entrega. Guardar primero
una copia de las carpetas que se reemplazarán. El paquete no contiene ni cambia
contraseñas, configuración privada, audios ni registros existentes.

Las dos tablas nuevas se crean automáticamente con `CREATE TABLE IF NOT EXISTS`.
Si el usuario MySQL de la aplicación no tiene permiso CREATE, importar primero
`database/migrations/2026_10_17_telefonia_atencion_analista.sql` en la base del CRM
desde phpMyAdmin. La migración es aditiva: no borra ni reclasifica llamadas.

Después de subir, cerrar y volver a abrir la sesión del CRM para recargar también
el documento persistente del teléfono. Hacerlo con las extensiones sin llamada
activa. Actualizar sólo el iframe del CRM no recarga los scripts del shell.

Comprobación con las extensiones reales:

- Transferir 100 → 101, contestar, capturar persona y otro contacto, finalizar.
- Abrir Registrar resultado, revisar borrador, vincular un expediente propio y guardar.
- Comprobar que cambió el contacto y se conservan etapa y devolución programada.
- Verificar dos datos de esa institución en dos conversaciones del mismo día:
  ambas quedan en historial y la meta diaria acredita una sola institución.
- Probar un buzón: no debe acreditar verificación ni crear institución nueva.
- Interrumpir la conexión antes de guardar: el error debe conservar los datos.

## Validación y alcance

Se ejecutaron 36 comprobaciones con MariaDB aislada, pruebas de conciliación y
regresión del SDK, y navegador real con Bootstrap y vistas PHP en cuatro tamaños
(1366×900, 820×460, 390×600 y 740×360). Se probaron errores de guardado, envío
doble, recuperación tardía, cierre, foco, desplazamiento y guardado correcto.

La validación local simula telefonía y red. No confirma todavía el audio ni los
webhooks de una llamada real en Hostinger. Esta entrega no configura colas,
presencia, grabación automática ni reglas de devolución en la cuenta Zadarma.
La conciliación automática usa número, extensión y cercanía temporal; una
coincidencia temporal no sustituye la confirmación explícita del analista.
Los expedientes y métricas históricos conservan sus datos anteriores; no se
fabrican evidencias retroactivas. La detección de institución repetida al crear
conserva la comprobación de nombre y territorio en la cartera propia; una
deduplicación global de carteras requiere una regla institucional adicional.

La auditoría previa sigue siendo una referencia del estado anterior. Las pruebas
estáticas de entradas y de conciliación se actualizaron al comportamiento actual;
la cobertura nueva está en `tests/telefonia_atencion_db.php` y
`tests/telefonia_atencion_browser.cjs` y en su flujo de GitHub Actions.

Regresión adicional: aprobaron 17 pruebas existentes. La prueba antigua
`telefonia_analista_historial_pasivo_smoke.js` conserva un fallo previo: su
simulador prohíbe un elemento `div` que el resumen de llamada ya crea en main.
Los archivos de esa prueba y su implementación no cambian en esta entrega;
el flujo transferido actual se valida con su prueba específica y el navegador.
