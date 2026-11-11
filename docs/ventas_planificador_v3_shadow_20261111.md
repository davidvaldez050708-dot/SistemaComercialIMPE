# Gestión comercial — Fase 3, planificador de movimientos en modo simulación

**Estado:** implementación de lectura + reglas, NO ACTIVA en Hostinger. Ningún archivo de telefonía, vista, controlador, shell, formulario V4 o bridge productivo ha cambiado.

## Hallazgos que bloqueaban la activación inmediata

1. `EmbudoComercialResultadoBridge::aplicar` sigue usando `EmbudoComercialEtapas::sugerenciaPorResultado`; actualmente `SIN_RESPUESTA`/`BUZON_VOZ` puede enviar a Recuperación desde el **primer** intento. No activar el contrato nuevo hasta reemplazar ese comportamiento en **una misma transición controlada**.
2. En V4 `PROSPECTO_POTENCIAL + REQUIERE_SEGUIMIENTO` puede guardarse con resultado principal `REQUIERE_SEGUIMIENTO`. El planificador debe recuperar las selecciones guardadas y separar *calificación comercial* y *tarea de seguimiento*, sin perder ninguna.
3. `INTERESADO` no es siempre `POTENCIAL` y `PAGO_CONFIRMADO` no equivale a `PROCESO_INSCRIPCION`. Por ahora se **bloquea la interpretación** de ambos códigos para conciliación de datos, no se ejecuta actualización ni SQL.
4. El contador general del PR #249 calculaba el total de toda la fecha. Para auditar correctamente el historial **1/3 → 2/3 → 3/3** cuando ya hay tres registros, se introdujo un límite opcional por fecha local y ID exacto de la llamada en `EmbudoComercialIntentosPbxRepository::resumenJornada`.

## Funcionamiento nuevo (sólo ensayo)

`EmbudoComercialPlanificadorV3::planificar(asesor, asignación, extensión, refPBX)`:

- Une, **por identidad exacta**, una fila de vínculo del prospecto con tramo PBX y resultado definitivo. Exige asignación vigente del asesor.
- Comprueba el estado comercial actual. Si es legacy u otro no reconocido, devuelve una advertencia de conciliación **sin proponer movimiento**.
- En V4 lee de `telefonia_ventas_resultado_selecciones` el conjunto guardado, sin confiar en nuevas declaraciones del navegador.
- Mantiene WhatsApp/SMS como `acciones`, seguimiento como `seguimiento_solicitado` y clasifica el resultado principal aparte.
- Interpreta la comunicación interrumpida según `contacto_antes_corte`; no confunde una llamada sin respuesta con una conversación.
- Calcula intentos del día **hasta la llamada concreta** y pasa ese total a la política del PR #248.
- Regresa `etapa_propuesta`, `subetapa_propuesta`, `mover`, `requiere_revision`, `intentos`, `acciones` y motivo.
- **Siempre devuelve `aplicable=false` y modo `SIMULACION_SOLO_LECTURA`**. NO ejecuta escrituras ni activa una vía pública para consultar datos ajenos.

## Ejemplos esperados de la simulación

| Hecho persistido | Plan |
| --- | --- |
| Nuevos + buzón, primer PBX | Nuevos · 1/3 |
| Nuevos + no contestó, segundo PBX | Nuevos · 2/3 |
| Nuevos + ocupado, tercer PBX | Proponer Recuperación · revisión al día siguiente, **sin ejecutarla** |
| Potenciales + 3 llamadas posteriores sin respuesta | Conservar Potenciales, revisión de la gestión |
| V4 potencial + seguimiento | Clasificación independiente de tarea; **sin** inferir calificación que aún no exista como evidencia |
| V4 sólo WhatsApp sin conversación | Resultado de no contacto, contar solo si se acredita un PBX saliente válido |
| Interesados/Pago confirmado actuales | Bloquear cualquier inferencia de nueva etapa y exigir conciliación |
| Resultado inscrito | Solicitar comprobación en módulo institucional, no crear inscripción |

## Qué falta antes de integrar a Hostinger

- **Conciliar SQL y versiones reales:** información de etapas vigentes, columna de asignación, valores legacy y totales de registros, con lectura segura y respaldo privado. No ejecutar migración de etapa a ciegas.
- **Definir evidencias de calificación**: intención de inscripción, programa y requisitos; crear persistencia del contexto comercial con validación desde servidor. No tomar un booleano del navegador.
- **Terminar la jornada**: contraste con horarios de trabajo por asesor y horarios preferidos del prospecto; día local como aproximación temporal actual.
- **Diseñar reversión transaccional e idempotencia** del puente comercial. El resultado PBX definitivo ya se guarda primero y no debe alterarse si falla el cambio de etapa. Un segundo intento de conciliación no debe guardar otra llamada.
- **Preparar vista/modal V4 contextual** únicamente al ingresar desde Gestión comercial, con las mismas clases y grabación que el actual. Mostrar intentos y subetapa sin permitir editar pruebas PBX ni duplicar el formulario.
- **Proteger drag & drop** para que no evada la exigencia de tres intentos, no pierda clasificación y mantenga accesibilidad.
- **Remarketing** sigue siendo revisión y elegibilidad, no envío automático al día siguiente; exclusiones y consentimiento se definen después.

## Pruebas

`tests/embudo_comercial_planificador_v3_unit.php` verifica interpretación de multiselección V4 y ausencia de escrituras.

`tests/embudo_intentos_pbx_db.php` usa MySQL **nueva y desechable** y reproduce con resultados ficticios 1/3, 2/3, 3/3, eventos del día siguiente, separación de asesor, legados y resultados contradictorios. La base se elimina en el bloque `finally`.

**No desplegar ni fusionar estas ramas a main hasta terminar la migración y validación integral.**
