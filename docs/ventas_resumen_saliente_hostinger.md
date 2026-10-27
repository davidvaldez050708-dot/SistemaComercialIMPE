# Resumen saliente del asesor: conservar el resultado pendiente

Entrega inmutable: `4d0e65f0cab70c7ac5f95a97776c71471506ec38`.

## Problema y corrección

Tras una llamada manual saliente, la capa visual usaba el resumen temporal de Marketing: vencía a los ocho segundos y también se retiraba cuando el motor publicaba `ready` o `idle`. La variante instalada en Hostinger devolvía sólo ese estado actual al consultar la llamada, aunque todavía se hubiera mostrado la atención finalizada.

Ventas conserva ahora el resumen de la llamada que acaba de terminar mientras espera su resultado. Los estados de reposo del motor y el paso del tiempo no lo resuelven. Se oculta al abrir el formulario y se retira al guardar el resultado; también admite el cierre explícito existente cuando corresponde y cede ante una llamada activa nueva. No recupera un resumen antiguo como si fuera una llamada activa al iniciar otra sesión.

La instalación cambia únicamente `public/javascript/telefonia_shell.js`. El paquete contiene la variante de GitHub y la variante exacta de Hostinger con SHA `77cfcf661fe350ee1647e7064e210a87e7b9f0474e39252fa650633d993e8e61`. Conserva la recuperación de registros antiguos de Opera y sus comentarios personalizados. La variante Hostinger corregida es `f5c0ed87996814d26cfc839536e3a47bed6c71d06e954ac7d4c2864b225f8b5b` en LF.

Se comprueba, sin modificarlo, `telefonia_ventas_shell.js` con SHA normalizado `405bd799c886803b46e1e2361000f51b449d0f8bcae5f7b3cad9b38d2a69f889`. El motor, el servicio de llamadas, las grabaciones, la limpieza instalada y la base de datos no se sustituyen. Marketing conserva su resumen temporal y las entrantes mantienen su flujo existente.

## Instalar

Terminar llamadas activas y cerrar todas las pestañas del CRM. Ejecutar el bloque completo desde SSH. El primer paso comprueba las versiones sin escribir en el sitio; después se aplica con respaldo privado. Si hay un archivo distinto, se detiene antes de publicar.

```bash
(
set -euo pipefail
umask 077
SITIO="$HOME/domains/fundacionrededumex.org/public_html"
PAQUETE="$HOME/impe-resumen-saliente-4d0e65f0"
RESPALDOS="$HOME/respaldos-impe-resumen-saliente"
BASE="https://raw.githubusercontent.com/davidvaldez050708-dot/SistemaComercialIMPE/4d0e65f0cab70c7ac5f95a97776c71471506ec38"
mkdir -p "$PAQUETE"
chmod 700 "$PAQUETE"
cd "$PAQUETE"
curl -fsSL "$BASE/tools/publicar_ventas_resumen_saliente.py" -o publicar_ventas_resumen_saliente.py
curl -fsSL "$BASE/tools/ventas_resumen_saliente_release.json" -o ventas_resumen_saliente_release.json
printf '%s\n' \
'a249564fa366a830188e3db0a7d24a79b48ad1dd0c7eba36a2f94a7224d98450  publicar_ventas_resumen_saliente.py' \
'fa55403e9bd3411043d3de32d3bf4dd0751d2689a86eb5b9e50dfd32a4936826  ventas_resumen_saliente_release.json' | sha256sum -c -
python3 publicar_ventas_resumen_saliente.py --site "$SITIO" --backup-root "$RESPALDOS" --check
python3 publicar_ventas_resumen_saliente.py --site "$SITIO" --backup-root "$RESPALDOS" --apply
)
```

Éxito: `APLICADO: resumen saliente de Ventas conservado hasta registrar o cerrar. Sin SQL. RESPALDO: ...`. Abrir el CRM de nuevo y recargar la página principal con Ctrl+F5. No es necesario borrar el almacenamiento de Opera, sesiones de agenda ni borradores.

## Comprobar

1. Llamada saliente contestada: colgar, esperar al menos 20 segundos. El resumen debe conservar «Registrar resultado». Abrir, guardar una vez y comprobar que el pendiente se retira.
2. Saliente cancelada antes de respuesta: esperar otros 20 segundos; no debe desaparecer sola. Usar el resultado que permita la llamada confirmada o el cierre explícito disponible. No se acredita una conversación ni una grabación por mantener visible el resumen.
3. Repetir en móvil. El resultado debe abrirse sin superponer el panel telefónico y sin recrear el motor.
4. Una llamada entrante nueva debe tener su propia identidad. El resultado anterior no debe asignarse al nuevo número.

## Validación local

La prueba nueva falló primero al publicar `ready` y reproducir la desaparición. Tras la corrección pasan seis escenarios por variante (GitHub y Hostinger): contestada/cancelada en 1366 y 390 px, transición a reposo, espera de dos minutos, guardado único, cierre explícito, nueva llamada y resumen temporal de Marketing. Se usan shell y formulario reales con proveedor y API simulados. Pasan además las regresiones de identidad, cierre transferido tardío, apertura del resultado y desbloqueo al guardar.

El publicador se verifica con ambas variantes, LF/CRLF, `--check` sin escrituras, dependencias distintas, archivo desconocido, respaldo, reinstalación y restauración. Estas pruebas no realizan llamadas al proveedor ni publican en Hostinger.

## Restaurar

Con las pestañas cerradas, sustituir la ruta de ejemplo por el respaldo exacto impreso al aplicar:

```bash
python3 "$HOME/impe-resumen-saliente-4d0e65f0/publicar_ventas_resumen_saliente.py" \
  --site "$HOME/domains/fundacionrededumex.org/public_html" \
  --backup-root "$HOME/respaldos-impe-resumen-saliente" \
  --restore "$HOME/respaldos-impe-resumen-saliente/RESPALDO-IMPRESO"
```

No modifica datos de llamadas. La restauración rechaza cambios posteriores en el archivo para evitar sobrescribirlos.
