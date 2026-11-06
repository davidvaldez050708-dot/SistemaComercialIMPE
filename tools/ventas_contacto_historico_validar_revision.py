#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Validación privada de una hoja de revisión histórica de WhatsApp.

Este programa NO se conecta a MySQL, Zadarma o internet y NO cambia datos.
Compara cada fila del CSV con la auditoría original por ID, usuario, extensión,
PBX, fecha, categoría y SHA. Solo admite modificar revision_humana y
observacion_revision. Produce un plan exclusivamente de lectura con los SI.
Python 3.6+.
"""
import argparse
import csv
import hashlib
import json
import os
import re
import stat
import sys
from pathlib import Path

import ventas_contacto_historico_preparar_revision as base

ALLOWED = set(("PENDIENTE", "SI", "NO"))


def validate(audit, reviewed, confirmacion_global=False):
    expected = base.load_report(audit)
    baseline = {str(row["resultado_id"]): row for row in expected}
    sha = hashlib.sha256(audit.read_bytes()).hexdigest()
    if reviewed.is_symlink() or not reviewed.is_file() or not stat.S_ISREG(reviewed.stat().st_mode):
        raise RuntimeError("CSV ausente o no regular")
    if reviewed.stat().st_size > 8 * 1024 * 1024:
        raise RuntimeError("CSV excesivamente grande")
    rows = []
    seen = set()
    with reviewed.open("r", encoding="utf-8-sig", newline="") as stream:
        reader = csv.DictReader(stream)
        if reader.fieldnames != base.HEADERS:
            raise RuntimeError("Las columnas del CSV fueron modificadas")
        for row in reader:
            identifier = row.get("resultado_id", "").strip()
            if not identifier.isdigit() or identifier not in baseline or identifier in seen:
                raise RuntimeError("ID de revisión desconocido o repetido: " + identifier[:50])
            seen.add(identifier)
            original = baseline[identifier]
            for key in base.HEADERS:
                if key in ("revision_humana", "observacion_revision"):
                    continue
                expected_value = str(original[key])
                if row.get(key, "") != expected_value:
                    raise RuntimeError("No se puede modificar el campo " + key +
                                       " del resultado ID " + identifier)
            if row["auditoria_sha256"] != sha:
                raise RuntimeError("Huella del origen incorrecta para resultado " + identifier)
            decision = row.get("revision_humana", "").strip().upper()
            if decision not in ALLOWED:
                raise RuntimeError("Decisión debe ser PENDIENTE, SI o NO. ID " + identifier)
            note = row.get("observacion_revision", "").strip()
            if len(note) > 500 or any(ord(c) < 32 and c not in "\t" for c in note):
                raise RuntimeError("Observación inválida: resultado " + identifier)
            if decision != "PENDIENTE":
                if len(note) < 10 and not (confirmacion_global and note == ""):
                    raise RuntimeError("La decisión requiere observación o confirmación global explícita. ID " + identifier)
                if original["categoria"] != base.CANDIDATE:
                    raise RuntimeError("No se puede aprobar automáticamente una llamada sin ANSWER/audio propio. ID " + identifier)
            elif note:
                raise RuntimeError("Una llamada PENDIENTE no admite observación definitiva. ID " + identifier)
            rows.append({
                "resultado_id": original["resultado_id"],
                "usuario_id": original["usuario_id"],
                "extension": original["extension"],
                "pbx_call_id": original["pbx_call_id"],
                "fecha_llamada": original["fecha_llamada"],
                "categoria": original["categoria"],
                "revision_humana": decision,
                "observacion_revision": note,
                "auditoria_sha256": sha
            })
    if len(rows) != len(expected) or seen != set(baseline):
        raise RuntimeError("El CSV debe contener todos los registros históricos sin omitir filas")
    return rows


def private_report(site, reviewed, rows, out_dir, confirmacion_global=False):
    site = site.resolve(strict=True)
    if not (site / "index.php").is_file():
        raise RuntimeError("Sitio no reconocido")
    out_dir = out_dir.resolve(strict=True)
    if (not out_dir.is_dir() or out_dir.is_symlink()
        or os.path.commonpath([str(site), str(out_dir)]) == str(site)
        or out_dir.stat().st_mode & 0o077):
        raise RuntimeError("El plan debe estar en una carpeta privada 0700 fuera de public_html")
    approved = [x for x in rows if x["revision_humana"] == "SI"]
    rejected = sum(x["revision_humana"] == "NO" for x in rows)
    pending = sum(x["revision_humana"] == "PENDIENTE" for x in rows)
    content = {
        "tipo": "PLAN_REVISION_HISTORICA_NO_APLICADO",
        "advertencia": "NO ES AUTORIZACIÓN DE CAMBIOS. Falta verificar datos vigentes y preparar respaldo transaccional.",
        "total": len(rows),
        "confirmacion_global_sin_notas": bool(confirmacion_global),
        "confirmacion_declarada": ("El responsable confirma los SI/NO del CSV sin descripciones por llamada."
            if confirmacion_global else ""),
        "auditoria_sha256": approved[0]["auditoria_sha256"] if approved else (rows[0]["auditoria_sha256"] if rows else ""),
        "csv_sha256": hashlib.sha256(reviewed.read_bytes()).hexdigest(),
        "pendientes": pending,
        "confirmadas_por_revision": len(approved),
        "descartadas_por_revision": rejected,
        "aprobadas": approved,
    }
    stem = hashlib.sha256(reviewed.read_bytes()).hexdigest()[:16]
    dest = out_dir / ("plan_contacto_whatsapp_" + stem + ".json")
    fd = os.open(str(dest), os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    try:
        with os.fdopen(fd, "w", encoding="utf-8") as output:
            json.dump(content, output, ensure_ascii=False, indent=2)
    except BaseException:
        dest.unlink()
        raise
    return dest, content


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--audit", required=True)
    parser.add_argument("--revision", required=True)
    parser.add_argument("--site", required=True)
    parser.add_argument("--out-dir", required=True)
    parser.add_argument("--confirmo-sin-observaciones", action="store_true",
        help="Declara que el responsable ya confirmó las decisiones SI/NO; no exige notas individuales.")
    args = parser.parse_args()
    rows = validate(Path(args.audit), Path(args.revision),
                    confirmacion_global=args.confirmo_sin_observaciones)
    dest, stats = private_report(Path(args.site), Path(args.revision),
                                 rows, Path(args.out_dir),
                                 confirmacion_global=args.confirmo_sin_observaciones)
    print("VALIDACION DE REVISION OK. Sitio y base intactos.")
    print("REVISADAS CON CONTACTO:", stats["confirmadas_por_revision"])
    print("REVISADAS SIN CONTACTO:", stats["descartadas_por_revision"])
    print("SIN REVISAR:", stats["pendientes"])
    print("PLAN NO APLICADO:", dest)
    print("No se cambiaron llamadas, audios, resultados ni contadores.")


if __name__ == "__main__":
    try:
        main()
    except Exception as error:
        print("ERROR:", error, file=sys.stderr)
        sys.exit(1)
