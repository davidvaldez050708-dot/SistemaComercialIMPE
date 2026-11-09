#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Inspeccion de anclas de conciliacion de 4 archivos personalizados de Telefonia.

SOLO LECTURA: no descarga archivos, no conecta a MySQL, no publica, no hace
respaldos ni edita. Facilita revisar las diferencias SIN compartir archivos
completos, llaves, llamadas ni datos de prospectos. Python 3.6+.

    python3 tools/diagnosticar_anclas_embudo_telefonia.py --site /path/public_html
"""
from __future__ import print_function
import argparse
import hashlib
from pathlib import Path
import re
import sys

FILES = {
    "app/services/TelefoniaResultadoVentasService.php": [
        ("funcion_guardar", r"function\s+guardar\s*\(", True),
        ("inicio_transaccion", r"->begin_transaction\s*\(", True),
        ("devolucion_resultado", r"if\s*\(\s*\$callbacks\s*\)\s*\$callbacks->aplicarResultado\s*\(", True),
        ("commit_resultado", r"->commit\s*\(", True),
        ("retorno_guardar", r"return\s*\[\s*['\"]pbx_call_id['\"]", True),
        ("bridge_ya_integrado", r"EmbudoComercialResultadoBridge", False),
    ],
    "app/views/telefonia/resultado_ventas_form.php": [
        ("form_ventas", r"data-sales-form", True),
        ("hidden_pbx", r"name\s*=\s*['\"]pbx_call_id['\"]", True),
        ("hidden_extension", r"name\s*=\s*['\"]extension['\"]", True),
        ("hidden_asignacion", r"name\s*=\s*['\"]prospecto_asignacion_id['\"]", False),
    ],
    "app/views/telefonia/ventas_llamadas.php": [
        ("contenedor_resultados", r"data-sales-history", True),
        ("destino_guardar", r"data-save-url\s*=", True),
        ("filtro_bandeja", r'<form[^>]*method\s*=\s*["\x27]get["\x27][^>]*>', True),
        ("ref_asignacion_ya_integrada", r"data-sales-assignment-id", False),
        ("hidden_asignacion_ya_integrado", r"name\s*=\s*['\"]prospecto_asignacion_id['\"]", False),
    ],
    "public/javascript/telefonia_ventas_resultados.js": [
        ("seleccion_llamada", r"form\.elements\.pbx_call_id\.value\s*=", True),
        ("seleccion_extension", r"form\.elements\.extension\.value\s*=", True),
        ("borrador", r"draftKey\s*\(", True),
        ("envio_formulario", r"new\s+FormData\s*\(\s*form\s*\)", True),
        ("ref_asignacion_js", r"form\.elements\.prospecto_asignacion_id\.value\s*=", False),
    ],
}

# No exponer líneas que posiblemente contengan secretos, tokens o datos privados.
REDACT = re.compile(
    r"(PASSWORD|SECRET|API_KEY|PRIVATE_KEY|Authorization|Bearer|"
    r"DB_USER|DB_HOST|DB_NAME|DB_PASSWORD|(?:['\"]?(?:access|refresh)_token['\"]?\s*=))",
    re.IGNORECASE
)
# Solo muestra las líneas de código relevantes, nunca archivos enteros.
HINTS = {
    "app/services/TelefoniaResultadoVentasService.php":
        ("$callbacks", "->commit", "return ['pbx_call_id'", "EmbudoComercialResultadoBridge"),
    "app/views/telefonia/resultado_ventas_form.php":
        ("name=\"extension\"", "name='extension'", "data-sales-form", "prospecto_asignacion_id"),
    "app/views/telefonia/ventas_llamadas.php":
        ("data-sales-history", "data-save-url", "impe-callback-filter-panel", "prospecto_asignacion_id"),
    "public/javascript/telefonia_ventas_resultados.js":
        ("form.elements.pbx_call_id", "form.elements.extension", "draftKey(", "prospecto_asignacion_id"),
}

def git_blob(data):
    return hashlib.sha1(("blob %d\0" % len(data)).encode("ascii") + data).hexdigest()

def extract(path, text):
    rows = text.splitlines()
    selected = []
    keys = HINTS[path]
    for index, line in enumerate(rows):
        if any(key in line for key in keys):
            selected.append(index)
    if len(selected) > 14:
        selected = selected[:14]
    for i in selected:
        for idx in range(max(0, i - 1), min(len(rows), i + 2)):
            line = rows[idx]
            if REDACT.search(line):
                print("    L{}: [CONTENIDO RESERVADO]".format(idx + 1))
            else:
                # Truncar líneas largas para que la salida sea sencilla.
                print("    L{}: {}".format(idx + 1, line[:280]))
        print("    ---")

def main():
    p = argparse.ArgumentParser()
    p.add_argument("--site", required=True)
    p.add_argument("--sin-fragmentos", action="store_true")
    opts = p.parse_args()

    root = Path(opts.site).resolve()
    if not root.is_dir():
        print("ERROR: directorio de sitio no disponible", file=sys.stderr)
        return 2

    print("=== CONCILIACION TELEFONIA / EMBUDO — SOLO LECTURA ===")
    conflict = 0
    for path, checks in FILES.items():
        print("\nARCHIVO: {}".format(path))
        target = root/path
        if not target.is_file() or target.is_symlink():
            print("  NO DISPONIBLE: archivo ausente o enlace simbolico")
            conflict += 1
            continue
        raw = target.read_bytes()
        print("  HASH GIT: {} | bytes: {}".format(git_blob(raw), len(raw)))
        try:
            content = raw.decode("utf-8")
        except UnicodeDecodeError:
            print("  BLOQUEADO: archivo no UTF-8")
            conflict += 1
            continue
        local_ok = True
        for title, pattern, required in checks:
            matches = list(re.finditer(pattern, content, re.I))
            count = len(matches)
            expected = "1" if required else "0 o 1"
            passcheck = count == 1 if required else count <= 1
            label = "OK" if passcheck else "REVISAR"
            print("  {} {}: coincidencias={} (esperado {})".format(label, title, count, expected))
            if not passcheck:
                local_ok = False
        if not local_ok:
            conflict += 1
        if not opts.sin_fragmentos:
            print("  FRAGMENTOS DE ESTRUCTURA (solo lineas orientativas):")
            extract(path, content)

    print("\nRESULTADO: {} archivos con anclas no reconocidas".format(conflict))
    print("SIN ESCRITURAS: sin SQL, sin permisos, sin llamadas o grabaciones alteradas")
    print("IMPORTANTE: anclas presentes NO autorizan aplicar automaticamente los cambios.")
    return 0 if conflict == 0 else 2

if __name__ == "__main__":
    try:
        sys.exit(main())
    except (OSError, ValueError) as e:
        print("BLOQUEADO: {}".format(e), file=sys.stderr)
        sys.exit(2)
