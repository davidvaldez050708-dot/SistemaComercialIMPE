# Gestión comercial IMPE — Contrato inicial de etapas, subetapas y llamadas

**Estado:** propuesta ejecutable y probada con PHP puro; **NO activa en Hostinger ni conectada al resultado PBX**. Esta fase no cambia teléfonos, grabaciones, bases de datos, formularios, tarjetas, importaciones ni seguimiento real.

## Principio operativo

La etapa expresa **avance comercial**, la subetapa expresa **situación** y la próxima acción expresa una **tarea**. No son sinónimos. Una llamada posterior que no se conteste no debe borrar un avance comercial auténtico.

| Etapa nueva | Alcance | Subetapas iniciales |
| --- | --- | --- |
| NUEVO / Nuevos | Sin conversación comprobada | Sin gestión, intento 1, intento 2 |
| CONTACTADO / Contactados | Hubo conversación; todavía no se califica como potencial | Primer contacto, información compartida, pendiente de calificación, evaluando |
| POTENCIAL / Potenciales | Intención de inscripción y calificación confirmadas | Calificado, documentos, presupuesto, listo para trámite |
| SEGUIMIENTO / Seguimiento | Decisión pendiente con próxima acción | Contacto acordado, presupuesto, apertura, esperando respuesta |
| PROCESO_INSCRIPCION / Proceso Inscripción | Solicitud/trámite real iniciado y acreditado | Solicitud iniciada, documentación, pago, validación |
| RECUPERACION (bandeja) | Gestión sin contacto que requiere revisión | Pendiente de revisión, elegibilidad, campaña, recontacto |
| DESCARTADO (bandeja) | Cierre con causa documentada | No interesado, número inválido, no requisitos, rechazo, no contactar |

**Inscritos** permanece en el módulo institucional y nunca se convierte en una sexta columna de ventas. El **pago verificado** es una subetapa de Proceso Inscripción, no una prueba automática de inscripción.

## Tres intentos por jornada

- El asesor intenta contactar hasta **tres veces durante su jornada**, respetando horario del prospecto y separando razonablemente las llamadas; **no** se bloquea el teléfono ni se obliga a llamar tres veces seguidas.
- Cuenta únicamente una llamada **saliente atribuida al asesor y asignación**, con **PBX confirmado, resultado guardado, número validado y referencia PBX distinta**. El navegador no entrega un contador confiable.
- No contestó, buzón, ocupado, locución/contestadora y comunicación interrumpida **sin conversación** pueden computar intentos sin contacto.
- Intentos 1/3 y 2/3: mantener **Nuevos** y crear gestión pendiente; 3/3: pasar a **Recuperación**, con fecha de **revisión al día siguiente**. No activar mensajes, campañas ni llamadas automáticas.
- **Llamadas entrantes, resultados duplicados, registros sin PBX, llamadas anteriores a la jornada o sin asignación acreditada no cuentan**. Si hubo conversación, se aplica su resultado humano.
- Para **Contactados, Potenciales, Seguimiento y Proceso Inscripción**, tres intentos sin contacto posteriores no los degradan automáticamente: se abre **revisión de gestión** sin perder compromiso, intención ni trámite.
- Una jornada incompleta no debe simular cumplimiento; queda pendiente. La lógica concreta de vencimientos/turnos y el reintento excepcional todavía requiere implementación y prueba con horario real.

## Matriz de decisión provisional

| Resultado | Decisión comercial |
| --- | --- |
| No contestó / Buzón / Ocupado / Mensaje automático | Contador PBX 1/3, 2/3, 3/3; recuperación al tercero para Nuevos |
| Se habló con el prospecto | Nuevos/Recuperación → Contactados; resto conserva etapa |
| Prospecto potencial | → Potenciales **únicamente con calificación confirmada**. Sin completar la calificación, Contactados o revisión |
| En seguimiento | Fecha/hora obligatorias y tarea transversal. Mover a Seguimiento sólo cuando hay decisión comercial pendiente, sin rebajar Potenciales/Inscripción |
| Fuera del presupuesto | Si acepta recontacto y existe fecha válida: seguimiento o subetapa de Potenciales; si rechazó definitivamente: Descartados; si no, revisión |
| Siguiente apertura | Seguimiento con fecha real de convocatoria **confirmada**; sin fecha, pendiente de revisión, nunca sumar dos meses automáticamente |
| No interesado | Descartados si el rechazo fue explícito, respetando exclusión de comunicaciones cuando se solicite |
| Número incorrecto o inexistente | Verificar número alterno y condición real; descarte por número inválido sólo cuando se confirma. No llamar tres veces a un número imposible |
| No cumple requisitos | Revisar alternativas/programa; no confundir con rechazo automático |
| Comunicación interrumpida | Preguntar si se habló: SI → conversación; NO → intento sin contacto |
| WhatsApp/SMS | Acciones complementarias separadas del resultado. Si sólo se selecciona envío, preguntar SI/NO hubo conversación; un mensaje NO prueba contacto |
| Inscrito | Solicitar verificación del registro institucional; **no se crea Inscritos desde teléfono** |
| Inicio de trámite | **Evento institucional acreditado**, no inferencia de una llamada, → Proceso Inscripción |
| Pago verificado | Evidencia de pago vinculada al trámite; no acredita Inscritos |

## Conciliación obligatoria antes de instalar nuevas etapas

En Hostinger aún existen códigos `INTERESADO`, `PAGO_CONFIRMADO` y una equivalencia histórica `EN_CONTACTO` → Recuperación. **No renombrar masivamente**:

- `INTERESADO` abarca casos de interés e información solicitada; algunos podrían calificarse como Potenciales, otros no. Requiere revisión de registros previos.
- `PAGO_CONFIRMADO` describe una acreditación, **no** un trámite iniciado por sí solo; verificar evidencia y vínculo antes de colocarlo en Proceso Inscripción.
- `EN_CONTACTO` es una representación antigua. Conservar fecha, original y movimiento histórico sin sobrescribirlos.
- No alterar `prospecto_embudo_movimientos`, `telefonia_ventas_resultados`, `telefonia_ventas_resultado_detalles`, audios ni referencias PBX.
- Antes de integrar, agregar migración **aditiva**, preflight de esquemas y SHAs reales, respaldo SQL privado, plan de transición y reversión.

## Política técnica de integración posterior (NO aplicada en esta entrega)

1. Servicio transaccional que derive intentos **de la BD** y verifique pertenencia, número completo y llamada real una sola vez, sin fiarse de la interfaz.
2. Adaptar resultado comercial **después** del commit de telefonía; mostrar por separado `resultado PBX guardado` y `estado comercial actualizado`. Si el puente falla, conservar la llamada y permitir reintentar sólo la transición comercial de forma idempotente.
3. Mantener V4 normal para llamadas fuera del embudo. Para llamadas de Gestión comercial añadir contexto de etapa, subetapa, intento 0/3..3/3, acción y próxima tarea, sin otro endpoint que duplique el guardado PBX.
4. Mantener drag & drop con confirmación y evidencias; no permitir mover a Recuperación con 1/3 ni a Inscritos/Pagos sin fuente autorizada. Conservar alternativa accesible a arrastrar en móvil y teclado.
5. Validar por separado el motor nocturno Meta y el inmediato de Aliados; **no activarlos mediante esta matriz**.
6. Recuperación significa **revisión para remarketing**, NO envío automático al día siguiente. La elegibilidad, consentimiento, exclusiones y campañas requieren una fase separada.

### Reglas aún por afinar con el equipo comercial

- Separación mínima realista entre los tres intentos de una jornada, casos cerca del cierre y atención de seguimientos vencidos.
- Qué evidencia constituye `calificacionConfirmada` (intención, programa, requisitos y documentación), según programa.
- Qué se hace con `NO_INTERESADO` cuando también solicita expresamente no contacto y cómo se propaga esa exclusión a campañas.
- Qué autoridad valida `inicio de inscripción`, `pago` e `inscripción confirmada`.
- Si debe existir una salida adicional por prospectos no elegibles para recontacto sin campaña.

**Código de esta fase:** `app/services/EmbudoComercialPoliticaPropuesta.php`. No tiene referencias desde controladores, JS, vistas ni servicios productivos. La prueba `tests/embudo_comercial_politica_propuesta_unit.php` usa únicamente datos ficticios.
