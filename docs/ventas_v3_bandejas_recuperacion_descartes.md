# Gestión Comercial V3 · Recuperación y Descartes con evidencia

## Alcance

**Borrador, no publicado en Hostinger.** Base del desarrollo:
`fix/ventas-v3-proteger-recuperacion-bridge-20261112`, con la guardia
de Recuperación ya publicada para asesores V3.

Las cinco columnas siguen sin cambios; `RECUPERACION` y `DESCARTADO`
son **bandejas separadas**, no una sexta columna ni un estado de inscripción.

### Recuperación

- Una tarjeta en `NUEVO` ofrece **Revisar Recuperación** únicamente con
  indicador PBX 3/3 y permiso `ventas.recuperacion.gestionar`.
- El backend vuelve a consultar las llamadas reales **en la misma jornada**
  local (últimos siete días) y exige al menos tres IDs PBX distintos
  vinculados a la asignación vigente, extensión y resultados salientes.
- Ningún contador enviado por el navegador se utiliza para decidir.
- Si existe conversación acreditada, no pasa a Recuperación por llamadas
  sin respuesta: se requiere revisar la calificación.
- Requiere confirmación, justificación, asesor propietario y permisos.
- Registra movimiento y subetapa `PENDIENTE_REVISION` en la misma
  transacción. Programa la revisión al siguiente día sin borrar la agenda
  anterior ni las actividades V3.
- **No se realizan llamadas, envíos ni movimientos automáticos**.

### Descartados

- Solo se permite decidir con evidencia PBX:
  - `NO_INTERESADO`: resultado definitivo enlazado
    `NO_INTERESADO`.
  - `NUMERO_INVALIDO`: resultado definitivo enlazado
    `NUMERO_INEXISTENTE`.
- Descarta desde Nuevos, Contactados, Potenciales, Seguimiento o
  Recuperación. Protege `PROCESO_INSCRIPCION` y los estados históricos.
- El descarte requiere confirmación y motivo; registra cambio y subetapa
  auditables y **no borra** agenda, resultados PBX ni historial.
- La bandeja Descartados es de consulta: **no ofrece marcar ni reactivar**.

### NO_CONTACTAR — conscientemente deshabilitado

La subetapa `NO_CONTACTAR` del catálogo existe como propuesta, pero la
nueva interfaz **no permite seleccionarla ni guardarla**. Marcarla sin
bloqueo efectivo en telefonía y mensajería sería inseguro. La próxima fase
requiere revisar y bloquear en servidor todas las vías de contacto, el
consentimiento vigente, las campañas y las importaciones, además de
registrar evidencia expresa y el mecanismo autorizado de rectificación.
Tampoco un `NO_INTERESADO` equivale automáticamente a `NO_CONTACTAR`.

No se debe afirmar que el sistema ya bloquea contacto omnicanal.

### Reactivación de Recuperación

- Requiere permiso `ventas.recuperacion.gestionar`, propietario y
  confirmación manual.
- Acredita una conversación humana vinculada a la misma asignación,
  **posterior al último ingreso auditado** en Recuperación.
- No admite una conversación anterior, una llamada simplemente contestada,
  un mensaje WhatsApp/SMS ni identificadores ajenos.
- Mueve a `CONTACTADO` y audita subetapa `PRIMER_CONTACTO`.
- El Bridge PBX V3 guarda los resultados telefónicos pero **no ejecuta**
  automáticamente Recuperación → Contactados. Los usuarios fuera de V3
  conservan su comportamiento anterior.

### Tablero y navegación

`bandejas_v3.php` presenta por separado las tarjetas RECUPERACION y
DESCARTADO con filtros, ficha/historial y paginación de 20. No inicia
llamadas desde estas bandejas. `EN_CONTACTO` histórico aparece como
«registro histórico» y no permite reactivación desde V3 sin conciliación.

`moverEtapa()` antiguo niega movimientos de entrada/salida de estas
bandejas para asesores habilitados a V3, evitando saltarse la nueva
comprobación. El resto de flujos legacy conserva su código.

### Seguridad y compatibilidad

- Posesión y permisos se validan de nuevo dentro del servicio.
- Controller V3: POST + CSRF de sesión obligatorio.
- Cada movimiento bloquea la asignación con `SELECT ... FOR UPDATE`,
  revisa la etapa y hace commit/rollback de la decisión auditable.
- Sin cambios en `TelefoniaResultadoVentasService`, WebRTC,
  Mis llamadas, recepción/transferencias, asignación de Meta/aliados
  ni credenciales.
- Sin migraciones ni alteraciones de datos existentes en el publicador.
- Se conserva el piloto PBX antiguo **retirado**; no se reincorpora.
- Si Hostinger difiere en cualquier archivo/dependencia esperados,
  el publicador **bloquea**, nunca fusiona o sobreescribe a ciegas.

## Pruebas incluidas

```bash
IMPE_V3_BANDEJAS_TEST_DB=YES php tests/ventas_v3_bandejas_db.php
node tests/ventas_v3_bandejas_browser.cjs
python3 tests/ventas_v3_bandejas_publicador_unit.py
```

La prueba SQL **solo se ejecuta contra MySQL/MariaDB aislados**: crea su
propia base aleatoria y la elimina; nunca debe ejecutarse contra el sitio
productivo. Chromium verifica confirmación, rechazo 409, CSRF y estado
del teléfono. El publicador se prueba en carpeta temporal contra blobs
Git exactos, restaurando después todos los archivos.

## Publicación posterior, solo por autorización

El publicador `tools/publicar_ventas_v3_bandejas_hostinger.py`
admite `--check`, `--apply`, `--rollback`, sobre exactamente **nueve
archivos**: servicio nuevo, vista V3 de bandejas, modal compartido,
CSS/JS nuevos, Bridge V3 conciliado, Repository, Controller y vista V3
principal. No modifica SQL ni permisos.

Primero descargar desde un SHA de commit inmutable, verificar hashes
Git del publicador y del paquete y ejecutar `--check` apuntando al
`public_html` real. El CHECK revisa las tablas, columnas, versiones
y dependencias. Si pasa, el usuario puede autorizar por separado
`--apply` con respaldo privado y `--rollback` disponible.

**No ejecutar APPLY si faltan pruebas CI, hay un archivo personalizado,
el esquema cambió o no se ha otorgado autorización explícita.**

## Limitaciones pendientes

1. Acreditación y bloqueo integral de `NO_CONTACTAR` en todos los canales.
2. Motivos de descarte que dependen de verificación institucional de
   requisitos, presupuesto o renuncia formal, aún no acreditables.
3. Gestión de tareas específicas dentro de Recuperación, sin confundirlas
   con llamadas o agenda legacy.
4. Conciliación de legados `EN_CONTACTO`, `INTERESADO`,
   `PAGO_CONFIRMADO` sin cambios automáticos.
