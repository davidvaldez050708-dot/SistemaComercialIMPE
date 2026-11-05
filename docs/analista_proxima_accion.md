# Próxima acción después de una llamada del analista

El formulario ocultaba y deshabilitaba la próxima acción a partir del paso 8. Además, eliminaba sus campos del POST informativo y el servidor avanzado no los guardaba. Se corrigen interfaz, validación y persistencia usando las columnas existentes; no hay migración ni DDL.

## Comportamiento

- `Solicitó volver a llamar`: propone `Volver a llamar` y exige fecha/hora futuras. El servidor rechaza otra acción incompatible o la falta de fecha, aunque se omita la validación del navegador.
- `Sin respuesta`, `Buzón de voz`, `Ocupado`: propone volver a llamar, con fecha/hora si se mantiene esa acción.
- Número incorrecto o fuera de servicio: propone investigar nuevo contacto; cualquier acción programada exige fecha/hora.
- Contacto correcto, referido o información solicitada: mantiene disponibles las acciones; no inventa una fecha ni un acuerdo de devolución. En las primeras etapas se conservan las acciones operativas de verificación/oficio, que no son una cita.
- `Otro`: permite elegir una acción y, si se programa, exige fecha/hora.
- `No interesado`: no exige ni crea programación. Se conserva el proceso de decisión/descarte existente.

Tener datos verificados nunca oculta estos campos ni vuelve a habilitar crédito por repetir casillas. Una programación nueva queda en la interacción con acción y fecha, y actualiza la próxima acción del expediente sin modificar su etapa ni las fechas de `reuniones_vinculacion`. Omitir una nueva programación en una interacción avanzada conserva la anterior.

El sistema sigue manejando una próxima acción general por expediente. Las reuniones conservan su registro y horario propios. La consulta distingue las programaciones nuevas de recordatorios anteriores cerrados por la verificación; no resucita esos recordatorios.

## Publicación

El paquete modifica seis archivos: un servicio nuevo de validación, el servicio informativo, controlador, modelo, resolvedor de flujo y JS de interacción. Preserva las variantes exactas de Hostinger, incluido el acceso territorial, y el diseño del panel. No cambia SDK, marcado, audio, transferencias ni grabaciones.

Descargar del mismo commit `tools/publicar_analista_proxima_accion.py` y `tools/analista_proxima_accion_release.json` a un directorio privado. Ejecutar `--check` y, después de compatibilidad satisfactoria, `--apply`, con los parámetros `--site` y `--backup-root`. Admite `--restore RUTA_DEL_RESPALDO` protegido frente a modificaciones posteriores. No ejecuta SQL ni carga el CRM; valida sintaxis PHP, genera respaldo privado y publica el JS con permisos 644. Compatible con Python 3.6.8.

## Evidencia

- 27 reglas puras de fechas, resultados y ruta verificada.
- Base desechable: fecha obligatoria, persistencia, notas, omisión de programación, control de acceso, reunión y ruta intactas, cero crédito de verificación.
- Vista PHP real: combinaciones de pasos 2/4/8/12 con y sin datos verificados, todos los resultados, obligatoriedad y POST completo.
- Regresión de las 158 comprobaciones de atención del analista, verificación vigente y horario de contacto.
- Instalación/reversión aisladas de variantes Git y Hostinger, rechazo de versiones desconocidas y conservación de las condiciones territoriales.

No se han hecho llamadas reales ni cambios en Hostinger o su base de datos.
