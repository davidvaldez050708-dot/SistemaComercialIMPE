# V3 — tarjetas con intentos PBX, subetapas y próximas gestiones (solo lectura)

**Estado:** diseño e implementación de visualización alternativa para supervisión, sin afectar el trabajo de los asesores. Esta rama NO está instalada en Hostinger, NO fusionada a producción ni activa para los usuarios operativos. Se basa en el piloto seguro del PR #254 que permanece OFF por defecto.

## Alcance

- Se amplía únicamente la vista `previsualizacion_v3.php`, que se consulta mediante el controlador de embudo y cuya ruta de previsualización requiere que `EmbudoComercialAcceso::esSupervisor()` sea verdadera. El Kanban antiguo `index.php`, su JavaScript de arrastre, ficha comercial y el formulario telefónico V4 **no se modifican**.
- Las cinco columnas continúan como **Nuevos, Contactados, Potenciales, Seguimiento, Proceso Inscripción**; los códigos legacy `INTERESADO`, `PAGO_CONFIRMADO` y otros desconocidos permanecen en **Clasificación pendiente**; Recuperación/Descartados siguen como bandejas externas.
- Cada tarjeta muestra la **subetapa vigente real** (o «Subetapa pendiente»), la **próxima acción con zona horaria local** y la fecha de revisión si existe.
- En Nuevos muestra **0/3 a 3/3 (o más)** intentos sin conversación del día local, con barra y aclaración «solo llamadas salientes PBX confirmadas y con resultado registrado». No confundirlo con el **número total de llamadas** del historial ni con un compromiso de mover de etapa.
- Para Contactados, Potenciales, Seguimiento y Proceso Inscripción muestra únicamente el número de intentos sin contacto hoy **si es mayor que cero**, sin degradar ni recalificar el prospecto.
- `EmbudoComercialV3IndicadoresVista::intentosDelDia` recibe los IDs **ya filtrados por el repositorio**; solo consulta un lote de máximo 120 asignaciones visibles, con usuario, extensión y PBX exactos, asignación vigente, resultado definitivo idéntico, salida comprobada y fecha original en America/Mexico_City. No expone referencias PBX, teléfono o credenciales.
- Resultados que cuentan: `SIN_RESPUESTA`, `BUZON_VOZ`, `OCUPADO`, `MENSAJE_AUTOMATICO`, `OTRO_SIN_CONTACTO`, `COMUNICACION_INTERRUMPIDA` con `contacto_antes_corte=NO`, y V4 complementario de `ENVIO_WHATSAPP/SMS` sin conversación. **No** cuentan entrantes, sin PBX vinculado, resultados no guardados, conversaciones, cortes con persona o llamadas de otra fecha.
- Para una asignación que tenga tres llamadas (dos sin respuesta y una conversación humana), el indicador debe mostrar **2/3**, no 3/3. Esto es especialmente relevante para el único prospecto legacy INTERESADO que tenía tres llamadas vinculadas, pero no se había comprobado que fueran tres sin respuesta.

## Garantías

- No agrega JS, botones, POST, procedimientos de escritura, transacciones ni tablas. Utiliza las dos tablas aditivas ya instaladas en Hostinger.
- No modifica la ruta de `TelefoniaResultadoVentasService::guardar`, WebRTC, dispositivos, grabaciones, Zadarma, PBX, ni el puente de movimientos en producción; se integra **solo en el repositorio y la vista previa alternativa**.
- Mantiene `20` tarjetas visibles por grupo; el indicador se obtiene en **una sola consulta adicional por lote** (no una consulta por cada tarjeta). Esto limita consumo de DB y evita nuevas conexiones persistentes. Nunca se ejecuta desde el tablero normal.
- El conjunto de IDs proviene exclusivamente de `EmbudoComercialRepository::condiciones` (propietario/supervisor + filtros), y el servidor vuelve a unir `prospecto_asignaciones` con `a.vigente=1` para el cálculo. El servicio **no se debe exponer como endpoint HTTP** ni se debe confiar en IDs enviados por JavaScript.
- La vista previa sigue sin permitir arrastrar tarjetas, llamar, registrar resultados, confirmar pagos ni crear inscripciones.
- Si la consulta de los indicadores falla, la vista previa muestra el error genérico del controlador existente; el tablero operativo y la telefonía no dependen de esta consulta.

## Validación

`tests/embudo_comercial_v3_previsualizacion_db.php`, ejecutado con MySQL 8 y MariaDB 10.11 **desechables**, ahora incluye PBX ficticios y verifica:
- 0/3, 1/3, 2/3, 3/3 sin hacer movimientos; el botón de arrastre no aparece en la previsualización.
- La comunicación interrumpida sin persona sí cuenta; conversación humana NO.
- Entrante, intento de ayer o sin vínculo del bridge NO cuentan.
- Tres llamadas en un caso legacy NO implican tres intentos fallidos.
- Los filtros de propiedad protegen los prospectos del asesor 9 de una consulta propia del asesor 8.
- Las subetapas y tareas siguen intactas, la vista normal no se altera, no se escribe ni en etapas ni en histórico.

## Pendiente antes de una publicación real

1. Confirmar los tres jobs de PHP/regresión, MySQL y MariaDB en CI para el SHA definitivo.
2. Comparar SHAs de los **archivos instalados** en Hostinger con el PR (especialmente `EmbudoComercialRepository.php`, `EmbudoComercialController.php` y `previsualizacion_v3.php`) y fusionar personalizaciones, sin reemplazos ciegos.
3. Verificar que el URL de previsualización no se muestra a asesores y que un supervisor ve los cinco embudos con el único INTERESADO en **Clasificación pendiente**.
4. Comprobar con llamadas ficticias PBX que la barra representa exclusivamente intentos fallidos reales, sin repetir resultado o conversación.
5. Finalizar política de **jornada laboral/horarios de contacto**: el prototipo cuenta por día local y **NO** prueba que se hayan distribuido correctamente los intentos a lo largo del turno.
6. Finalizar calificación y fuente institucional de Potenciales e Inscritos; no declarar este piloto como transición productiva.
7. Auditar capacidad de conexión y seguridad antes de permitir piloto en cuentas reales, especialmente en Hostinger con workers y conexiones persistentes.

**En particular, no habilitar `IMPE_VENTAS_V3_PILOTO` ni publicar la nueva ruta de tarjetas a los asesores por ahora.**
