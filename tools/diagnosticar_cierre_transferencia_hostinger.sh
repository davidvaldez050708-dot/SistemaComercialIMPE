#!/usr/bin/env bash
# Inspección de solo lectura para identificar variantes del shell en Hostinger.
# Nunca modifica código, base de datos, sesiones o archivos de producción.
set -euo pipefail
LIVE="${IMPE_CLOSE_PUBLIC:-$HOME/domains/fundacionrededumex.org/public_html}"
FILE="$LIVE/public/javascript/telefonia_shell.js"
[[ -f "$FILE" && ! -L "$FILE" ]] || {
  echo "ERROR: archivo publicado no encontrado o no es regular." >&2
  exit 1
}
echo "=== Diagnóstico de cierre telefónico (solo lectura) ==="
echo "Archivo: public/javascript/telefonia_shell.js"
echo "Líneas: $(wc -l < "$FILE" | tr -d ' ')"
echo "SHA256: $(sha256sum "$FILE" | cut -d' ' -f1)"
if command -v node >/dev/null 2>&1; then
  if node --check "$FILE" >/dev/null 2>&1; then
    echo "Sintaxis JS: OK"
  else
    echo "Sintaxis JS: ERROR (no se modificó nada)"
  fi
fi
# Extrae SOLO código alrededor de los puntos necesarios para recuperar el
# modal final. Nunca imprime configuraciones, URL, API keys o números del CRM.
python3 - "$FILE" <<'PY'
import pathlib,re,sys
lines=pathlib.Path(sys.argv[1]).read_text(encoding='utf-8').splitlines()
checks=[
 ('función render',r'^\s*const render\s*=\s*function\s*\('),
 ('indicador finishedSdk',r'\b(?:const|let|var)\s+finishedSdk\b'),
 ('referencias finishedSdk',r'\bfinishedSdk\b'),
 ('estado entrante finalizado',r'incoming-finished'),
 ('estado de llamada recibida',r'const incoming\s*='),
 ('visibilidad modal',r'const customVisible\s*='),
 ('transferencia disponible',r'const workingCall\s*='),
 ('panel lateral transferencia',r'if \(transferPane\) transferPane\.hidden'),
 ('control Colgar',r'const hangupIncoming\s*='),
 ('aviso final en modal',r'incomingTitle\.textContent\s*='),
 ('cierre manual',r"closeFinished\?\.addEventListener"),
 ('resumen saliente',r'let recentFinishedOutgoing\s*='),
 ('seguimiento acciones',r'const pendingFor\s*='),
]
for name,pat in checks:
    matches=[i for i,line in enumerate(lines) if re.search(pat,line)]
    print('%s: %s' % (name, ','.join(str(i+1) for i in matches[:9]) or 'NO'))
# Cuerpo de la cabecera de render y de colgado: limitarse a identificadores
# y estructura, evitando imprimir expresiones que contengan datos privados.
print('--- Estructura de render, sin valores ni credenciales ---')
indexes=[i for i,line in enumerate(lines) if re.search(r'^\s*const render\s*=\s*function\s*\(',line)]
if len(indexes)==1:
    for i in range(indexes[0], min(indexes[0]+32,len(lines))):
        line=lines[i].strip()
        # Mostrar únicamente nombres de símbolos referidos en expresiones.
        markers=('finishedSdk','incoming','outgoing','pending','preview',
                 'state','active','phase','analystIncomingResults',
                 'customVisible','render','ongoing')
        if any(m in line for m in markers):
            names=[m for m in markers if re.search(r'\b'+re.escape(m)+r'\b',line)]
            print('%d: %s' % (i+1,','.join(names)))
else:
    print('No se encontró una cabecera única de render.')
# Fragmentos muy delimitados para distinguir la variante sin publicar el
# archivo completo. Se redactan literales, correos, números largos y URLs.
print('--- Fragmentos estructurales (literales ocultos) ---')
for label,pattern,before,after in [
    ('transición estado',r'^\s*const updateState\s*=\s*function',0,16),
    ('estado modal',r'incomingTitle\.textContent\s*=',2,12),
    ('visibilidad',r'const customVisible\s*=',5,17),
    ('colgado',r'^\s*const hangupIncoming\s*=\s*function',0,33),
]:
    matches=[i for i,line in enumerate(lines) if re.search(pattern,line)]
    print('[%s]' % label)
    if len(matches)!=1:
        print('  ESTRUCTURA NO ÚNICA')
        continue
    pos=matches[0]
    for i in range(max(0,pos-before),min(len(lines),pos+after)):
        line=lines[i]
        line=re.sub(r"(['\"]).*?\1",'<texto>',line)
        line=re.sub(r'https?://\S+','<url>',line)
        line=re.sub(r'[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+','<correo>',line)
        line=re.sub(r'\b\d{7,}\b','<numero>',line)
        print('%d: %s' % (i+1,line[:165]))
print('=== FIN. Ningún archivo modificado ===')
PY
