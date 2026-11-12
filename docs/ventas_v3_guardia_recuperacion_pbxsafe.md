# Guardia de Recuperación V3: conciliación del Bridge telefónico

## Auditoría Hostinger 12 de noviembre de 2026

La consulta privada **solo lectura** confirmó:
- Fecha local: 2026-11-12
- Asignaciones vigentes en las tablas comerciales auditadas: **2**
- Analizadas: **2**; sin páginas pendientes
- **ETAPA_PROTEGIDA: 2**
- **CANDIDATO_REVISION_3: 0** en esa muestra.

No hay todavía suficientes casos reales de Nuevos en el conjunto observado
para validar la regla del tercer intento sobre producción. La auditoría no
prueba ni niega que hubiera movimientos históricos indebidos; solo muestra
un riesgo demostrable en el código del Bridge V1.

## Cambio propuesto

En `EmbudoComercialResultadoBridge.php`, exclusivamente para asesores
**autorizados a la interfaz V3 mediante control privado**, se bloquea la
aplicación automática de la sugerencia `RECUPERACION` del motor heredado.
El resultado telefónico definitivo y el vínculo PBX se registran como antes;
el método retorna `vinculada=true, movida=false`.

Se conserva el comportamiento anterior de asesores que aún **no** tienen
habilitado V3. Tampoco se modifica `TelefoniaResultadoVentasService.php`,
mis llamadas, grabaciones, asignaciones, otras etapas ni historial
telefónico. No se reincorpora el piloto `IMPE_VENTAS_V3_PILOTO` retirado
de Hostinger.

Este cambio **no activa** automáticamente el movimiento al tercer intento.
La política de 3 llamadas PBX distintas y la revisión de Recuperación
requieren integración posterior con evidencias y autorización.

## Publicación segura por separado

`tools/publicar_ventas_v3_proteccion_recuperacion_hostinger.py`
actúa sobre **un solo archivo**: el Bridge. Usa el publicador verificado de
Potenciales como librería de integridad/backup/rollback; verifica hashes
de dependencias sin editarlas. Cualquier personalización en Hostinger
diferente a la base esperada provoca `BLOQUEADO`.

Para instalar, primero ejecutar **CHECK**, con el paquete descargado desde
un commit inmutable y verificando el blob Git del publicador antes de usarlo.
**No ejecutar APPLY automáticamente**: confirmar compatibilidad y autorizar
el cambio, porque afecta la decisión de movimientos automáticos de V3.

En caso de aprobación, el publicador admite `--apply` con respaldo privado
fuera de `public_html` y `--rollback` con el respaldo exacto.
No realiza migraciones ni escrituras a la BD directamente.

## Pruebas incluidas

- `tests/ventas_v3_recuperacion_bridge_proteccion_unit.php`:
  V3 bloqueado, no V3 intacto, cinco resultados de no contacto, 1/2/3 intentos,
  vínculo PBX antes del guard y piloto ausente.
- `tests/ventas_v3_recuperacion_guardia_publicador_unit.py`:
  CHECK/APPLY/ROLLBACK sobre copia temporal con hashes exactos,
  bloqueo de un archivo personalizado y dependencias intactas.
- CI MySQL/MariaDB: suites existentes del contador PBX y del tablero;
  Chromium: comportamiento de V3 no alterado.

## Pendientes de Recuperación/Descartados

- Revisar semántica de llamadas con `ENVIO_WHATSAPP`, `ENVIO_SMS` y
  `COMUNICACION_INTERRUMPIDA` frente a `contacto_antes_corte`.
- Decidir quién autoriza el pase desde `NUEVO` al tercer intento y cuándo.
- Crear bandeja de revisión de elegibilidad, sin contacto automático.
- Separar descarte definitivo, supresión **NO CONTACTAR** y revisión de
  reactivación. No mezclar "sin interés" con consentimiento de contacto.
- Integrar pruebas de concurrencia y escenarios reales autorizados, sin
  tocar prospectos productivos para simular conteos.
