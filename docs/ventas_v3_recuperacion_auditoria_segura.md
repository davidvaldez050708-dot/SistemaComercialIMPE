# Recuperación y Descartados V3 — auditoría PBX previa

## Estado y alcance

Este paquete **NO** habilita Recuperación ni Descartados. No modifica
`EmbudoComercialResultadoBridge.php`, las llamadas Zadarma, el historial,
los resultados, las asignaciones, los permisos ni la base de datos.

Se incorpora un evaluador puro, un lector SSH privado y pruebas. La auditoría
verifica únicamente asignaciones vigentes y registra agregados anónimos, nunca
nombres, teléfonos, IDs de asignación ni identificadores PBX.

## Hallazgo prioritario: V1 y V3 no coinciden

- `EmbudoComercialEtapas::sugerenciaPorResultado()` propone
  `RECUPERACION` ante el primer `SIN_RESPUESTA`, `OCUPADO`,
  `BUZON_VOZ`, `MENSAJE_AUTOMATICO` u `OTRO_SIN_CONTACTO`.
- `EmbudoComercialResultadoBridge::aplicar()`, cuando recibe un resultado
  asociado y existe permiso suficiente, conserva la posibilidad de aplicar
  esa sugerencia; no exige tres intentos. La propuesta puede afectar
  `NUEVO`, `CONTACTADO` o `SEGUIMIENTO`.
- La política comercial V3 `EmbudoComercialPoliticaPropuesta` exige
  **tres intentos PBX independientes durante la misma jornada** para proponer
  revisión de Recuperación exclusivamente desde `NUEVO`.
- El planificador V3 existente es **solo lectura**; no corrige ni sustituye
  automáticamente el movimiento anterior.
- No podemos afirmar que en producción ocurriera un movimiento equivocado
  sin consultar evidencia del historial real. El conflicto es una ruta
  de ejecución demostrable del código y debe atenderse antes de activar
  cualquier automatización V3.

## Evidencia mínima de intentos

El auditor reutiliza el contador PBX V3 existente:
`EmbudoComercialV3IndicadoresVista::intentosDelDia()`. Exige las coincidencias
exactas de asignación vigente, extensión, identificador PBX, llamada
saliente, resultado registrado y fecha local de México. Deduplica
`extensión|identificador PBX` por asignación.

Un envío de WhatsApp/SMS sin llamada saliente PBX **no** acredita intento.
Un resultado `ENVIO_*` de catálogo V4 se cuenta únicamente cuando existe
una llamada PBX saliente verificada; esta interpretación debe revisarse como
parte de la política final. La lectura no puede acreditar por sí misma
que la llamada no se dirigiera a un contacto marcado `NO_CONTACTAR`.

## Diagnóstico SSH (sin publicaciones)

Descargar desde un commit revisado los siguientes archivos en una carpeta
privada FUERA de `public_html`:

- `tools/ventas_v3_recuperacion_auditoria_hostinger.php`
- `app/services/EmbudoComercialV3RecuperacionAuditoria.php`

La herramienta requiere una copia exacta de las dependencias ya publicadas
(`EmbudoComercialV3IndicadoresVista.php`, `EmbudoComercialEtapas.php`,
`EmbudoComercialResultadoBridge.php`); si se personalizaron, **bloquea**.

Uso, desde el directorio privado que contiene `tools/` y `app/services/`:

```bash
php tools/ventas_v3_recuperacion_auditoria_hostinger.php \
  --site="$HOME/domains/fundacionrededumex.org/public_html" \
  --dia=2026-11-12 --limite=120 --offset=0
```

Opcional: `--asesor-id=N` limita a un solo asesor. Si `HAY_MAS: SI`,
continuar con `--offset=120`, `--offset=240`, etc., manteniendo
la misma fecha. Cada ejecución inspecciona como máximo 120 asignaciones.

Códigos por muestra: `SIN_INTENTOS`, `INTENTO_1`, `INTENTO_2`,
`CANDIDATO_REVISION_3`, `YA_EN_RECUPERACION`,
`LEGADO_REQUIERE_REVISION`, `DESCARTADO_SIN_REACTIVACION`,
`ETAPA_PROTEGIDA`. `CANDIDATO_REVISION_3` es solamente una
**recomendación para revisión**, no una autorización de movimiento.

La conexión usa `START TRANSACTION READ ONLY`. No hay SQL de escritura,
migraciones, endpoints HTTP, nuevas llamadas, SMS, WhatsApp ni registros
de modificación en el CRM. La herramienta no tiene opción `--apply`.

## Pruebas y continuación

Ejecutar:
```bash
php tests/ventas_v3_recuperacion_auditoria_unit.php
IMPE_EMBUDO_PBX_TEST_DB=YES php tests/embudo_intentos_pbx_db.php
```
La segunda prueba **solo puede ejecutarse en una instancia MySQL/MariaDB
desechable**, donde crea y elimina su propia base de pruebas.

Siguiente fase, **solo después de revisar el diagnóstico**:

1. Conciliar el comportamiento del bridge PBX antiguo, preservando los
   resultados definitivos y cualquier personalización en Hostinger.
2. Definir si los casos V4 `ENVIO_*` realmente acreditan intentos en la
   política comercial, y cómo se audita `NO_CONTACTAR`.
3. Implementar un flujo autorizado de revisión de Recuperación, sin mover
   automáticamente prospectos ni enviar campañas.
4. Separar descarte, supresión de contacto y reactivación; no asociar
   “Descartado” con “no contactar” en todos los casos.
5. Probar en MySQL, MariaDB, Chromium y Hostinger con CHECK → APPLY →
   respaldo/ROLLBACK en cambios posteriores que lo ameriten.
