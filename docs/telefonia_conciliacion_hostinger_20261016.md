# Conciliación de telefonía Hostinger · octubre de 2026

## Alcance de este PR

Se revisó el respaldo de **cinco archivos** del CRM activo de Hostinger y
se contrastó contra `main`. El respaldo original **no se agrega al repositorio**.
Este PR consolida únicamente la diferencia funcional que aún no estaba resuelta
en `main`: la incompatibilidad entre dos implementaciones del contexto.

- **`TelefoniaTransferenciaContextoService.php`**: conserva el esquema de
  producción `telefonia_transferencia_contextos_v2`, incluida su clave
  `(origen_usuario_id, referencia_llamada)`, vigencia de 90 segundos y
  la consulta a la tabla que ya existe. A diferencia de la versión anterior
  del servidor, confirma además la extensión y el caller ID mediante el
  webhook `NOTIFY_INTERNAL` antes de vincular un expediente.
- **`TelefoniaController.php`**: conserva la validación del token,
  territorio y destino, admite `pbx_call_id` y
  `referencia_llamada` con comprobación de igualdad, y pasa los nueve
  argumentos del servicio v2. Si `registrar()` devuelve `false`, no emite
  transferencia telefónica y devuelve 409.
- **`TelefoniaEntrantesAnalistaService.php`**: ya en `main` existe
  conciliación visual conservadora PBX/WebRTC (PR #64). No se sustituye por
  la versión anterior del servidor que agregaba ambas listas sin conciliar.
- **`telefonia_enrutamiento.js`**: `main` ya transmite alias históricos
  con una comprobación de institución y llamada concreta (PR #68). Se
  conserva la versión nueva, sin reinstalar el formulario anterior.
- **`telefonia_analista_historial_pasivo.js`**: `main` ya hace los
  reintentos de lectura de contexto durante la sesión del SDK. Se mantiene
  el horizonte acotado de unos 13 segundos sin intercambiar todo el
  observador WebRTC por la variante local.

## Por qué se preserva `_v2`

El CRM de producción registra en `telefonia_transferencia_contextos_v2`.
La implementación anterior de GitHub operaba una tabla diferente,
`telefonia_transferencia_contextos`. Reemplazar un solo archivo sin
adaptar su controlador y su tabla provocaba la pérdida de identificación.
No es necesaria una migración destructiva ni borrar la tabla histórica.

## Validaciones sin cambios en producción

```sh
php -l app/services/TelefoniaTransferenciaContextoService.php
php -l app/controllers/TelefoniaController.php
php tests/telefonia_contexto_v2_reconciliacion_smoke.php
php tests/conciliacion_entrantes_pbx_webrtc_smoke.php
php tests/contexto_transferencias_protegido_smoke.php
php tests/contexto_transferencia_alias_hostinger_smoke.php
node --check public/javascript/telefonia_enrutamiento.js
node --check public/javascript/telefonia_analista_historial_pasivo.js
```

## Pruebas obligatorias antes de instalar

1. Ejecutar pruebas estáticas y de sintaxis en **Windows o staging**.
2. Comprobar la estructura SQL existente de `_v2` sin alterar datos;
   verificar consultas preparadas, índices y usuario receptor.
3. Probar la transferencia directa con Recepción 100 y Analista 101,
   primero con contexto confirmado y luego con fallo deliberado de
   contexto. Un error 409 no debe enviar DTMF.
4. Confirmar que no cambian las salientes, las grabaciones de las
   llamadas ni la representación de sesiones ya clasificadas.
5. Desplegar únicamente tras revisar un diff contra **los archivos
   actuales** de producción y crear respaldo recuperable.

## Pendiente separado

Los cortes a los 7 y 19 segundos vistos en Diego **no están explicados**
por estos cinco archivos. La PBX registró ambas contestaciones y fin;
no existe evidencia suficiente de quién colgó. No se modifica lógica
de `HANGUP`, el puente WebRTC ni los audios en este PR.

**Este PR no despliega ni sustituye archivos de Hostinger.**
