# Auditoría del flujo telefónico de Analistas

Fecha: 16 de octubre de 2026, America/Mexico_City.
Código revisado: `a884d491d7c9d88bb82b714a394dabab863f9b9e`.

Esta revisión describe el código y propone cambios. No modifica llamadas,
expedientes, métricas ni la configuración de la centralita. Se contrastaron
diez componentes del repositorio con GitHub. No se hicieron llamadas reales
ni se consultó la base de datos de Hostinger.

## 1. Regla de negocio confirmada

Una llamada efectiva permite confirmar, corregir u obtener información útil
de una institución: teléfono, correo, persona o área de contacto. El ejemplo
«hablé con recepción y me dieron el nombre, número y correo de la persona
correcta» es una verificación efectiva cuando queda documentado y vinculado
con una conversación real.

Para la meta de cada analista se mantiene **máximo una verificación efectiva
por institución y día**, aunque existan varias llamadas útiles. Esta regla
fue confirmada por el usuario durante la revisión. Se conservan todas las
llamadas y evidencias; el límite afecta al crédito en la meta.

Conviene distinguir tres conceptos en pantalla y en la base de datos:

| Concepto | Qué acredita |
|---|---|
| Conversación con una persona | Se consiguió contacto humano. |
| Verificación efectiva | Se confirmó, corrigió u obtuvo algún dato institucional, con evidencia de la llamada. |
| Ficha completa | Están reunidos los datos exigidos para la siguiente etapa, por ejemplo destinatario, cargo y correo para un oficio. |

Una conversación informativa sin verificación queda registrada sin sumar a
la meta. Un rechazo comercial puede aportar verificación si durante él se
confirmaron datos; el motivo comercial y la verificación se registran por
separado. La existencia de una grabación no determina la efectividad.

## 2. Para qué sirve hoy «Datos de llamada»

El botón abre una libreta de captura durante una llamada activa. Permite
anotar institución o prospecto, persona que atiende, teléfono, correo y notas.
**Guardar borrador** escribe esos datos en `sessionStorage`, por usuario e
identificador exacto de llamada. La lectura acepta borradores de menos de
24 horas. No es un respaldo en el servidor y su recuperación depende de la
sesión del navegador.

Al registrar una entrante del Analista, el formulario busca ese borrador e
importa los datos. Sólo al guardar el resultado se crea la interacción y,
si corresponde, un expediente nuevo. No debe crear un expediente al anotar
el primer dato ni acreditar la meta por pulsar Guardar borrador.

Limitaciones observadas:

- El guardado requiere pulsar el botón; no hay autoguardado en ese código.
- El borrador no se comparte entre Tania y el Analista. El contexto de
  transferencia revisado transporta la institución identificada, no esa
  libreta completa con el motivo y los datos anotados por Recepción.
- El único consumidor de `call-draft` localizado es el formulario de
  entrantes. La captura se puede mostrar en otras llamadas, pero no se
  encontró esa importación en el registro saliente de Seguimiento.
- La persona que atendió y el contacto al que refiere son personas distintas
  en muchos casos; la libreta tiene un único campo de persona.
- La captura y el cierre usan identificadores que pueden alternar entre PBX
  y sesión WebRTC. La importación por coincidencia exacta necesita conservar
  explícitamente la relación entre ambos para evitar borradores huérfanos.

Fuentes: [captura y guardado del borrador](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/blob/a884d491d7c9d88bb82b714a394dabab863f9b9e/public/javascript/telefonia_enrutamiento.js#L541),
[importación al registrar](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/blob/a884d491d7c9d88bb82b714a394dabab863f9b9e/public/javascript/seguimiento_entrantes_analista.js#L219).

## 3. Escenarios y comportamiento esperado

Esta tabla propone el flujo operativo; no significa que todas las opciones
ya estén implementadas.

| Escenario | Flujo recomendado | Crédito para la meta |
|---|---|---|
| Tania transfiere una institución con seguimiento propio | Precargar expediente, responsable y motivo; el analista confirma identidad y registra la atención. | Una si verifica datos. Transferir por sí mismo no suma. |
| La institución llama desde otro número | Buscar por institución y territorio; confirmar antes de vincular. Mantener separado el número de origen del teléfono institucional. | Una si existe verificación. |
| Institución nueva | Buscar duplicados; capturar institución, territorio y contactos; crear expediente y atención en una sola transacción. | Una si se documenta verificación y se confirma la llamada. Crear el expediente no basta. |
| «Llame a otra persona; éste es su teléfono o correo» | Resultado Contacto referido; separar quién informó de la persona referida, su cargo y sus medios de contacto; programar la siguiente llamada. | Una, con datos concretos y evidencia de la conversación. |
| Confirman datos existentes sin cambiarlos | Mostrar el valor confirmado y registrar quién lo confirmó y cuándo. | Una; confirmar también es verificar. |
| Piden información o consultan un trámite | Guardar atención y próxima acción, preservando la etapa del seguimiento. | Cero si no verificaron ningún dato. |
| Dicen No interesado | Guardar el resultado comercial; descartar sólo mediante decisión expresa con motivo. | Una sólo si además hubo verificación. |
| Buzón, sin respuesta, fuera de servicio o número incorrecto | Conservar intento y resultado sin acreditar verificación ni crear una institución. Aplicar la política de audio correspondiente. | Cero. |
| No se identifica a la institución | Guardar pendiente o sin identificar con notas recuperables; permitir vinculación posterior por personal autorizado. | Cero hasta acreditar institución, conversación y verificación. |
| Pertenece a otra cartera o territorio | Mostrar responsable permitido y transferir o solicitar reasignación. Evitar crear un duplicado para poder atenderla. | Atribución al analista que realmente verifica y está autorizado. |
| Analista ocupado, desconectado o no contesta | Mantener una salida operativa para Recepción: otro destino autorizado, recuperación o devolución programada, según soporte de la centralita. | La transferencia fallida no suma. |
| Llamada entre extensiones | Mantenerla como comunicación interna. | Cero para la meta institucional. |
| Dos llamadas verifican la misma institución el mismo día | Guardar ambas con sus aportaciones y próximas acciones. | Una para la meta diaria. |

## 4. Qué ya protege el sistema

El registro entrante exige permisos y pertenencia del expediente, comprueba
el fin de la llamada y evita crear instituciones a partir de llamadas sin
respuesta. Una clasificación por llamada y analista, junto con la transacción
del expediente y la interacción, reduce registros duplicados. Las sesiones
observadas sólo por el navegador requieren confirmación humana y no deben
acreditar por sí solas una verificación técnica. Las llamadas internas se
separan del contacto institucional.

El directorio de transferencias limita los destinos por extensión activa,
permisos, territorio y responsable. Conviene conservar estas validaciones al
ampliar los formularios; no convertir la extensión o un número coincidente
en prueba de identidad institucional.

## 5. Hallazgos prioritarios

### P0. Una llamada conciliada puede perder la vía de verificación

El listado asocia una sesión WebRTC a un registro PBX cuando la coincidencia
es única. La vista elige después `SDK_WEBRTC` para registrar si existe el ID
de sesión conciliada, incluso cuando la tarjeta conserva identidad PBX.
El formulario oculta entonces las evidencias y el servidor rechaza las
verificaciones de sesiones SDK. Por esa ruta, una conversación real puede
guardarse sin posibilidad de acreditar los datos verificados.

Debe mantenerse una identidad común y distinguir «PBX confirma conversación
de este analista» de «sólo observado por navegador». La conciliación visual
o una coincidencia de teléfono y hora no deben promover evidencia técnica
por sí solas. Si falta una confirmación técnica, conservar los datos de
negocio como verificación pendiente y conciliarlos cuando llegue evidencia
suficiente, sin exigir otra llamada ni duplicar la interacción.

Fuentes: [selección del origen para registrar](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/blob/a884d491d7c9d88bb82b714a394dabab863f9b9e/app/views/seguimiento_vinculacion/llamadas_entrantes.php#L132),
[visibilidad de evidencias](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/blob/a884d491d7c9d88bb82b714a394dabab863f9b9e/public/javascript/seguimiento_entrantes_analista.js#L64),
[validación al registrar](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/blob/a884d491d7c9d88bb82b714a394dabab863f9b9e/app/services/TelefoniaEntrantesAnalistaService.php#L572).

### P0. Contacto referido está incompleto en las entrantes

Seguimiento ofrece «Me proporcionaron otro contacto», con nombre, cargo,
nuevo teléfono y nuevo correo. La entrada transferida no incluye ese
resultado en su mapa ni ofrece los mismos campos. En la rama de expediente
existente, teléfono y correo capturados no se aplican como correcciones;
se procesan en la rama de institución nueva. Es insuficiente para el ejemplo
principal del usuario.

Unificar el registro saliente y entrante sobre una misma operación de
verificación. Mostrar datos actuales y propuestas, exigir confirmación de
qué se incorporará y conservar el valor anterior y quién aportó el nuevo.
Los cierres negativos también necesitan una opción coherente en las
entrantes; no obligar a seleccionar Contacto correcto u Otro cuando se
escuchó un buzón. Un dato nuevo de persona o área debe poder documentarse
como evidencia cuando sea realmente institucional, aunque no cambie el
teléfono. Actualmente el referido de Seguimiento exige teléfono o correo
nuevo y no cubre por sí solo todos los casos de nombre o área nuevos.

Fuentes: [resultados de entrantes](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/blob/a884d491d7c9d88bb82b714a394dabab863f9b9e/app/services/TelefoniaEntrantesAnalistaService.php#L432),
[contacto referido en Seguimiento](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/blob/a884d491d7c9d88bb82b714a394dabab863f9b9e/app/services/InteraccionRutaService.php#L83).

### P0. Verificar un dato puede alterar la ruta y quitar una devolución

Entrantes pasa `datos_verificados = 1` cuando existe alguna evidencia. El
modelo convierte ese valor en estado `DATOS_VERIFICADOS`. También elimina
la próxima acción programada al alcanzar ese estado. Por tanto, la combinación
«Solicitó volver a llamar» más un dato confirmado puede perder su programación.

Para una institución existente, el mismo registro puede devolver la etapa a
`CONTACTANDO` o `DATOS_VERIFICADOS`, sin conservar necesariamente una etapa
posterior del expediente. La operación informativa de Seguimiento ya tiene
otra lógica que conserva la ruta; las dos vías necesitan alinearse.

La verificación parcial puede contar como efectiva sin declarar completa la
ficha ni avanzar o reiniciar el proceso comercial. Las devoluciones deben
mantenerse hasta completarlas, cancelarlas o sustituirlas expresamente.

Fuentes: [dato enviado por entrantes](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/blob/a884d491d7c9d88bb82b714a394dabab863f9b9e/app/services/TelefoniaEntrantesAnalistaService.php#L622),
[limpieza de próxima acción](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/blob/a884d491d7c9d88bb82b714a394dabab863f9b9e/app/models/SeguimientoVinculacionModel.php#L1331),
[resolución de etapa](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/blob/a884d491d7c9d88bb82b714a394dabab863f9b9e/app/models/SeguimientoVinculacionModel.php#L1821).

### P1. Indicadores y evidencias deben tener un criterio común

Desempeño cuenta llamadas efectivas con el marcador de verificación, vínculo
telefónico, duración positiva y deduplicación por institución y fecha. El
contador mensual llamado «contactos efectivos» en otro servicio cuenta
instituciones con CONTACTADO o un marcador de contacto humano y usa otra
deduplicación. Son medidas distintas y sus nombres pueden inducir a pensar
que deben coincidir.

Además, la evidencia se representa mediante texto dentro de notas. Debe
guardarse como datos estructurados: campo confirmado o corregido, valor,
persona que lo informó, analista, llamada y fecha. El texto libre no debería
decidir si una verificación obtiene crédito.

Usar una definición común y distinguir explícitamente llamadas, contactos
humanos, verificaciones efectivas, verificaciones pendientes e instituciones
con ficha completa. Mantener el límite de la meta acordado por el usuario.

Fuentes: [Desempeño](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/blob/a884d491d7c9d88bb82b714a394dabab863f9b9e/app/models/DesempenoModel.php#L150),
[contador mensual](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/blob/a884d491d7c9d88bb82b714a394dabab863f9b9e/app/services/AnalistaDashboardIntegrityService.php#L62).

### P1. Captura fiable y prevención de duplicados

Pasar la libreta a borrador con respaldo en el servidor y autoguardado,
señalando si está guardando, guardado o pendiente de sincronizar. Recuperar
lo capturado después de recargar o cerrar accidentalmente la ventana.
Compartir sólo el resumen autorizado necesario al transferir.

La comprobación explícita de institución nueva revisa nombre dentro del mismo
analista y territorio. Ampliar la detección antes de crear a coincidencias
normalizadas y a instituciones de otras carteras, mostrando responsable sin
permitir edición o apropiación no autorizada. El teléfono es una pista,
porque un conmutador puede corresponder a varias instituciones o contactos.

### P2. Operación de mini call center

El directorio revisado comprueba configuración activa, no demuestra que el
analista esté conectado o libre en ese momento. Incorporar estados de agente
Disponible, En llamada, Cerrando atención, Pausa y Desconectado, con una fuente
de presencia verificable y vencimiento de estados antiguos.

Definir y probar recuperación ante destino ocupado, rechazo, falta de
respuesta, caída de Internet, sesión caducada y eventos PBX tardíos. La cola,
espera y recuperación de transferencias requieren verificar qué soporta y
cómo está configurada la centralita; esta auditoría no inspeccionó esa cuenta.

Para supervisión: pendientes de cierre, devoluciones vencidas, transferencias
intentadas y confirmadas, destino que respondió, tiempo de espera y tiempo de
conversación por tramo. Recepción no debe recibir el crédito de verificación
del Analista sólo por haber transferido la misma llamada.

## 6. Flujo objetivo

1. Identificar la llamada y su tramo, conservando las referencias PBX y WebRTC.
2. Buscar y confirmar institución, expediente y responsable permitido.
3. Capturar durante la conversación con borrador recuperable.
4. Registrar quién atendió y, por separado, a quién refirió la atención.
5. Elegir resultado comercial y señalar qué datos se confirmaron, corrigieron
   u obtuvieron. Revisar antes de actualizar el expediente.
6. Guardar conjuntamente interacción, cambios de contacto, evidencia y próxima
   acción. Mostrar si la verificación está acreditada o pendiente técnica.
7. Aplicar la meta una sola vez por institución y día, sin perder llamadas,
   aportaciones ni tareas posteriores. Conservar la etapa salvo cambio expreso.

Las actualizaciones de contacto deben conservar sus valores anteriores y su
origen. Conciliar después la evidencia telefónica de una atención pendiente
no debe crear otra llamada ni reescribir un resultado definitivo de Recepción.

El botón podría llamarse **«Capturar datos»** durante la conversación y el
formulario de cierre **«Registrar atención y verificación»**. La pantalla
debería indicar con lenguaje simple: «Conversación registrada», «Verificación
efectiva: cuenta en la meta», «Ya contabilizada hoy» o «Pendiente de confirmar
la llamada». Estos textos deben depender de la decisión del servidor.

## 7. Orden de implementación y aceptación

**Primera entrega:** corregir identidad PBX/WebRTC, contacto referido en
entrantes, conservación de etapa y próximas acciones. Usar un solo contrato
de verificación para llamadas salientes y entrantes. Probar antes de modificar
las métricas o reclasificar registros históricos.

**Segunda entrega:** evidencias estructuradas, borradores recuperables,
contactos diferenciados, duplicados y métricas compartidas.

**Tercera entrega:** presencia de agentes, tratamiento de transferencias no
atendidas, cola si la centralita lo permite y supervisión operativa.

Casos mínimos de aceptación:

- Confirmar un teléfono existente cuenta una vez, con quién lo confirmó.
- Contacto referido guarda nombre y cargo separados de quien atendió.
- Institución nueva no duplica otra cartera y se crea de forma atómica.
- Una llamada informativa conserva la etapa comercial.
- Verificar datos y programar una devolución conserva la fecha futura.
- Dos verificaciones de la misma institución en el día producen un crédito.
- Un evento tardío concilia la misma atención sin duplicarla ni perder borrador.
- Una sesión sólo WebRTC conserva captura y evidencia pendiente sin inventar PBX.
- Buzón, sin respuesta e internas no acreditan verificación institucional.
- Caída de red, doble envío y recarga no pierden datos ni crean duplicados.
- El analista sin permiso no puede vincular otra cartera ni cambiar responsable.
- La regla y la fecha local coinciden en tablero, meta diaria y Desempeño.

## 8. Comprobaciones realizadas y límites

Se revisaron vista, JavaScript, servicios, controladores y consultas de
indicadores. Una invocación aislada de la función real de resolución de etapa,
sin construir conexión de base de datos, confirmó que
`SOLICITO_LLAMAR_DESPUES` con el indicador de datos verificados devuelve
`DATOS_VERIFICADOS`; sin ese indicador devuelve `CONTACTANDO`. La eliminación
de la próxima acción se verificó en el código del mismo método de registro.

Pruebas existentes ejecutadas:

| Prueba | Resultado |
|---|---|
| `telefonia_entrantes_bridge_smoke.js` | Aprobada; incluye un error SDK simulado esperado. |
| `telefonia_entrantes_visual_smoke.php` | Aprobada; comprobación estática de componentes. |
| `telefonia_analista_entrantes_smoke.php` | Falló una expectativa estática del flujo WebRTC/shell. |
| `telefonia_entrantes_interfaz_smoke.php` | Falló una expectativa estática de presentación del modal. |
| `conciliacion_entrantes_pbx_webrtc_smoke.php` | No puede ejecutar: invoca un método `conciliarHistorialPbxWebrtc` que la clase actual no define; la conciliación está dentro de `listar`. |

Los fallos estáticos no demuestran por sí solos que una llamada real no
funcione. Sí muestran que la cobertura debe actualizarse y ampliarse con
pruebas de flujos completos. Las consecuencias descritas de los hallazgos se
deducen del código; no se midió su frecuencia ni se modificaron registros
reales para reproducirlas. La cuenta y configuración real de Zadarma, las
migraciones instaladas y los archivos privados de Hostinger no se verificaron.
