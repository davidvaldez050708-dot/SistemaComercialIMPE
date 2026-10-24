# Instalación selectiva de conexión telefónica

Entrega fija: `fbc0d9ccc71a61b6628b3cf908e18542c23ec600`.
Código operativo: `5c823dcd61ddadec1b549137ecc8c73b3c65a22f`.
PR [157](https://github.com/davidvaldez050708-dot/SistemaComercialIMPE/pull/157).

No se ha publicado en Hostinger. No usar `git pull` sobre `public_html` ni copiar una rama completa. Sólo se instalan siete archivos. Sin SQL ni cambios a credenciales. El manifiesto reconoce las variantes exactas reconstruidas a partir de los hashes/diffs enviados el 24 de octubre. Una diferencia nueva devuelve CONFLICTO antes de escribir.

## 1. Descargar y comprobar, sin modificar el sitio

Se puede ejecutar con el CRM abierto: este paso sólo descarga la entrega fuera del sitio, comprueba integridad y lee los candidatos. Los hashes se verificaron contra los bytes de Git y raw GitHub, no contra la conversión CRLF de Windows.

```bash
set -e
umask 077
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
ENTREGA="$HOME/actualizaciones-impe/telefonia-conexion-20261024"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/fbc0d9ccc71a61b6628b3cf908e18542c23ec600"
mkdir -p "$ENTREGA"
curl -fsSL --retry 2 "$BASE/tools/publicar_telefonia_conexion.py" -o "$ENTREGA/publicar_telefonia_conexion.py"
curl -fsSL --retry 2 "$BASE/tools/telefonia_conexion_release.json" -o "$ENTREGA/telefonia_conexion_release.json"
(
  cd "$ENTREGA"
  printf '%s\n' \
    'bb0505391e389d214a1a0d197e184dc694e628ac2974241784e098b1be64a199  publicar_telefonia_conexion.py' \
    'fa3819a78e196c5e3f2c1adf3ef246f61af1496188a89d9153af1c7f74589972  telefonia_conexion_release.json' \
    | sha256sum -c -
)
python3 -B "$ENTREGA/publicar_telefonia_conexion.py" \
  --site "$SITIO" \
  --backup-root "$HOME/respaldos-impe-telefonia-conexion" \
  --check
```

Resultado esperado: siete rutas COMPATIBLE/ACTUALIZADO, sus huellas antes/después y `CHECK OK: sin escrituras`. Si hay CONFLICTO, fallo de integridad o sintaxis: detenerse y enviar la salida; no forzar sustituciones. PHP es obligatorio; si Node está disponible, también se valida JS. La sintaxis JS se valida además en CI.

## 2. Aplicar en una ventana sin llamadas

Antes de aplicar: comprobar que el workflow dirigido «Telefonia conexion persistente» del PR aprobó; revisar el CHECK de Hostinger. Marketing/Recepción, Analistas y Asesores deben terminar sus llamadas y cerrar **todas** las pestañas del CRM y teléfonos directos del navegador. El código anterior no participa en la nueva reserva; una pestaña antigua abierta impediría verificar un arranque limpio.

Este comando vuelve a verificar todos los archivos antes de escribir, crea un respaldo privado y restaura los cambios realizados si una escritura falla:

```bash
python3 -B "$ENTREGA/publicar_telefonia_conexion.py" \
  --site "$SITIO" \
  --backup-root "$HOME/respaldos-impe-telefonia-conexion" \
  --apply
```

Guardar la ruta exacta que imprime `APLICADO. RESPALDO:`. Si todo estaba actualizado, no se crea un respaldo nuevo. Una instalación parcial también tiene respaldo de los archivos que realmente se cambiaron.

Abrir una nueva sesión del CRM. No recargar un shell que conserve una llamada. Probar el inicio automático; si el navegador bloquea micrófono/audio, usar «Activar teléfono» y el permiso del sitio. El indicador permanece sin verificar mientras no haya evidencia real y permiso de audio.

## 3. Aceptación real

[Diagnóstico, diseño, archivos, simulaciones y matriz completa](telefonia_conexion_persistente_20261024.md).

En los tres perfiles, comprobar con llamadas reales:

- Timbrado y contestación después de 15 minutos en otra pestaña, 30 minutos en otra aplicación y 60 minutos en segundo plano, sin recargar.
- Sólo un motor entre dos pestañas; cierre del dueño en reposo entrega la reserva a la otra. Foco/minimización no la entrega.
- Red offline/online recupera recepción; una llamada activa no se marca terminada por la pérdida de red.
- Salientes, entrantes, transferencias directas/consultadas 100→101 y 100→150, resultados y grabaciones por tramos conservan su funcionamiento.

El widget de Zadarma usa push en reposo y crea SIP al llamar/contestar. `sipRegistered:false` en reposo puede ser normal. Para diagnóstico sin datos personales, en consola del shell se puede consultar:

```javascript
IMPE_TELEPHONY_SHELL.isPhoneReady()
```

No se declara superada la aceptación operativa basándose sólo en este booleano. Registrar el timbrado, audio bidireccional y resultado de cada ensayo.

## 4. Restaurar

Terminar las llamadas y cerrar todas las pestañas telefónicas. Asignar a RESPALDO la ruta exacta impresa al aplicar. No usar una ruta inventada ni un respaldo de otra entrega.

```bash
RESPALDO="PEGA_AQUI_LA_RUTA_EXACTA_DEL_RESPALDO"
python3 -B "$ENTREGA/publicar_telefonia_conexion.py" \
  --site "$SITIO" \
  --backup-root "$HOME/respaldos-impe-telefonia-conexion" \
  --restore "$RESPALDO"
```

Verifica el manifiesto y las copias, restaura bytes anteriores y elimina exclusivamente el archivo nuevo creado por esa aplicación. Admite restaurar una aplicación interrumpida; si hay modificaciones posteriores ajenas, bloquea la restauración completa para conservarlas. No modifica SQL.

## Límites de operación

La coordinación cubre el mismo origen/perfil del navegador; otro equipo o perfil no comparte esa reserva. El PC suspendido, navegador cerrado, pestaña congelada/descartada, red o proveedor caído no permiten garantizar recepción. La sesión del proveedor tiene límites propios, incluida la vigencia de sus claves. No se usan reinicios periódicos ni audio silencioso para eludir las políticas del navegador.
